<?php

declare(strict_types=1);

namespace Kcs\Filesystem\StreamWrapper;

use Kcs\Filesystem\Filesystem;
use Kcs\Filesystem\StreamWrapper\Command\StreamWriteCommand;
use Kcs\Filesystem\StreamWrapper\Helper\UserGuesser;
use Kcs\Filesystem\Visibility;
use Kcs\Stream\Exception\StreamError;
use Kcs\Stream\ReadableStream;
use Kcs\Stream\ResourceStream;

use function array_keys;
use function array_merge;
use function assert;
use function class_exists;
use function in_array;
use function str_replace;
use function stream_get_wrappers;
use function stream_wrapper_register;
use function stream_wrapper_unregister;
use function trigger_error;
use function ucwords;

use const E_USER_WARNING;

final class StreamWrapper
{
    public const string LOCK_STORE = 'lock_store';
    public const string LOCK_TTL = 'lock_ttl';

    public const string IGNORE_VISIBILITY_ERRORS = 'ignore_visibility_errors';

    public const string EMULATE_DIRECTORY_LAST_MODIFIED = 'emulate_directory_last_modified';

    public const string UID = 'uid';
    public const string GID = 'gid';

    public const string VISIBILITY_FILE_PUBLIC = 'visibility_file_public';
    public const string VISIBILITY_FILE_PRIVATE = 'visibility_file_private';
    public const string VISIBILITY_DIRECTORY_PUBLIC = 'visibility_directory_public';
    public const string VISIBILITY_DIRECTORY_PRIVATE = 'visibility_directory_private';
    public const string VISIBILITY_DEFAULT_FOR_DIRECTORIES = 'visibility_default_for_directories';

    public const array DEFAULT_CONFIGURATION = [
        self::LOCK_STORE => 'flock:///tmp',
        self::LOCK_TTL => 300,

        self::IGNORE_VISIBILITY_ERRORS => false,
        self::EMULATE_DIRECTORY_LAST_MODIFIED => false,

        self::UID => null,
        self::GID => null,

        self::VISIBILITY_FILE_PUBLIC => 0644,
        self::VISIBILITY_FILE_PRIVATE => 0600,
        self::VISIBILITY_DIRECTORY_PUBLIC => 0755,
        self::VISIBILITY_DIRECTORY_PRIVATE => 0700,
        self::VISIBILITY_DEFAULT_FOR_DIRECTORIES => Visibility::Private,
    ];

    /** @var array<string, Filesystem> */
    public static array $filesystems = [];

    /** @var array<string, array{lock_store: string, lock_ttl: int, ignore_visibility_errors: bool, emulate_directory_last_modified: bool, uid: int|null, gid: int|null, visibility_file_public: int, visibility_file_private: int, visibility_directory_public: int, visibility_directory_private: int, visibility_default_for_directories: Visibility}> */
    public static array $config = [];

    private readonly Stream $current;

    /** @var resource */
    public $context;

    public function __construct(Stream|null $current = null)
    {
        $this->current = $current ?? new Stream();
    }

    /** @param array{lock_store?: string, lock_ttl?: int, ignore_visibility_errors?: bool, emulate_directory_last_modified?: bool, uid?: int|null, gid?: int|null, visibility_file_public?: int, visibility_file_private?: int, visibility_directory_public?: int, visibility_directory_private?: int, visibility_default_for_directories?: Visibility} $configuration */
    public static function register(
        string $protocol,
        Filesystem $filesystem,
        array $configuration = [],
        int $flags = 0,
    ): bool {
        if (self::streamWrapperExists($protocol)) {
            return false;
        }

        self::$config[$protocol] = array_merge(self::DEFAULT_CONFIGURATION, $configuration);
        self::$filesystems[$protocol] = $filesystem;

        if (null === self::$config[$protocol][self::UID]) {
            self::$config[$protocol][self::UID] = UserGuesser::getUID();
        }

        if (null === self::$config[$protocol][self::GID]) {
            self::$config[$protocol][self::GID] = UserGuesser::getGID();
        }

        return stream_wrapper_register($protocol, self::class, $flags);
    }

    public static function unregister(string $protocol): bool
    {
        if (! self::streamWrapperExists($protocol)) {
            return false;
        }

        unset(self::$config[$protocol], self::$filesystems[$protocol]);

        return stream_wrapper_unregister($protocol);
    }

    public static function unregisterAll(): void
    {
        foreach (self::getRegisteredProtocols() as $protocol) {
            self::unregister($protocol);
        }
    }

    /** @return array<int, string> */
    public static function getRegisteredProtocols(): array
    {
        return array_keys(self::$filesystems);
    }

    public static function streamWrapperExists(string $protocol): bool
    {
        return in_array($protocol, stream_get_wrappers(), true);
    }

    /** @param array<int|string> $args */
    public function __call(string $method, array $args): mixed
    {
        $class = __NAMESPACE__ . '\\Command\\' . str_replace('_', '', ucwords($method, '_')) . 'Command';
        if (class_exists($class)) {
            return $class::exec($this->current, ...$args);
        }

        return false;
    }

    public function stream_close(): void // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
    {
        if (! isset($this->current->handle)) {
            return;
        }

        if ($this->current->workOnLocalCopy) {
            assert($this->current->handle instanceof ResourceStream);
            $this->current->handle->rewind();

            try {
                $this->current->filesystem->write($this->current->file, $this->current->handle);
            } catch (StreamError $e) {
                trigger_error(
                    'stream_close(' . $this->current->path . ') Unable to sync file : ' . $e->getMessage(),
                    E_USER_WARNING,
                );
            }
        }

        $this->current->handle->close();
        unset($this->current->handle);
    }

    public function stream_flush(): bool // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
    {
        if (! isset($this->current->handle)) {
            trigger_error(
                'stream_flush(): Supplied resource is not a valid stream resource',
                E_USER_WARNING,
            );

            return false;
        }

        $success = true;
        if ($this->current->workOnLocalCopy) {
            assert($this->current->handle instanceof ReadableStream);
            $currentPosition = $this->current->handle->tell();
            $this->current->handle->rewind();

            try {
                $this->current->filesystem->write($this->current->file, $this->current->handle);
            } catch (StreamError $e) {
                trigger_error(
                    'stream_flush(' . $this->current->path . ') Unable to sync file : ' . $e->getMessage(),
                    E_USER_WARNING,
                );
                $success = false;
            }

            if ($currentPosition !== false) {
                $this->current->handle->seek($currentPosition);
            }
        }

        $this->current->bytesWritten = 0;

        return $success;
    }

    /** @return array<int|string,int|string>|false */
    public function stream_stat(): array|false // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
    {
        /** @phpstan-ignore-next-line */
        return $this->url_stat($this->current->path, 0);
    }

    public function stream_write(string $data): int // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
    {
        $size = StreamWriteCommand::exec($this->current, $data);

        if ($this->current->writeBufferSize && $this->current->bytesWritten >= $this->current->writeBufferSize) {
            $this->stream_flush();
        }

        return $size;
    }
}
