<?php declare(strict_types=1);

namespace Tests\Local;

use ErrorException;
use Kcs\Filesystem\Exception\OperationException;
use Kcs\Filesystem\Exception\UnableToCreateDirectoryException;
use Kcs\Filesystem\Local\LocalFilesystem;
use Kcs\Filesystem\Runtime\RuntimeInterface;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;

class LocalFilesystemTest extends TestCase
{
    use ProphecyTrait;

    private static ?array $lastError = null;

    /**
     * @var RuntimeInterface|ObjectProphecy
     */
    private $runtime;
    private LocalFilesystem $fs;

    protected function setUp(): void
    {
        $clearLastError = static function () {
            self::$lastError = null;
        };

        $getLastError = static function () {
            $ret = self::$lastError;
            self::$lastError = null;

            return $ret;
        };

        $this->runtime = $this->prophesize(RuntimeInterface::class);
        $this->runtime->isDir('/')->willReturn(true);
        $this->runtime->clearLastError()->will(fn() => $clearLastError());
        $this->runtime->getLastError()->will(fn() => $getLastError());

        $this->fs = new LocalFilesystem('/', [], $this->runtime->reveal());
    }

    public function testConstructShouldCreateRootFolder(): void
    {
        $calls = 0;
        $this->runtime->isDir('/')->will(function () use (&$calls): bool {
            return $calls++ > 0;
        });

        $this->runtime->mkdir('/', 0755, true)
            ->willReturn(true)
            ->shouldBeCalled();

        new LocalFilesystem('/', [], $this->runtime->reveal());
    }

    public function testConstructShouldCreateRootFolderForWinPaths(): void
    {
        $calls = 0;
        $this->runtime->isDir('C:\\root')->will(function () use (&$calls): bool {
            return $calls++ > 0;
        });

        $this->runtime->mkdir('C:\\root', 0755, true)
            ->willReturn(true)
            ->shouldBeCalled();

        new LocalFilesystem('C:\\root', [], $this->runtime->reveal());
    }

    public function testConstructShouldCreateRootFolderWithDefaultPermissions(): void
    {
        $calls = 0;
        $this->runtime->isDir('/')->will(function () use (&$calls): bool {
            return $calls++ > 0;
        });

        $this->runtime->mkdir('/', 0700, true)
            ->willReturn(true)
            ->shouldBeCalled();

        new LocalFilesystem('/', ['dir_permissions' => 0700], $this->runtime->reveal());
    }

    public function testConstructWillThrowIfRootCannotBeCreated(): void
    {
        $this->runtime->isDir('/root/folder')->willReturn(false);
        $setError = static function () {
            self::$lastError = [
                'message' => 'Error',
                'file' => __FILE__,
                'line' => 42,
                'type' => E_USER_ERROR,
            ];
        };

        $this->runtime->clearLastError()->shouldBeCalled();

        $this->runtime->mkdir('/root/folder', 0755, true)
            ->will(function () use ($setError) {
                $setError();
                return false;
            })
            ->shouldBeCalled();

        try {
            new LocalFilesystem('/root/folder/', [], $this->runtime->reveal());
            self::fail('Expected UnableToCreateDirectoryException to be thrown');
        } catch (UnableToCreateDirectoryException $e) {
            $previous = new ErrorException('Error', 0, E_USER_ERROR, '', 0);
            $ex = new UnableToCreateDirectoryException('/root/folder', $previous);
            self::assertEquals($ex->getMessage(), $e->getMessage());
            self::assertEquals(E_USER_ERROR, $e->getPrevious()->getSeverity());
            self::assertEquals(__FILE__, $e->getPrevious()->getFile());
            self::assertEquals(42, $e->getPrevious()->getLine());
        }
    }

    public function testExistsShouldReturnFalse(): void
    {
        $this->runtime->isFile('/file')->willReturn(false);
        $this->runtime->isDir('/file')->willReturn(false);
        $this->runtime->isLink('/file')->willReturn(false);

        self::assertFalse($this->fs->exists('/file'));
    }

    public function testExistsShouldReturnTrueIfItsAFile(): void
    {
        $this->runtime->isFile('/file')->willReturn(true);
        self::assertTrue($this->fs->exists('/file'));
    }

    public function testExistsShouldReturnTrueIfItsADir(): void
    {
        $this->runtime->isFile('/file')->willReturn(false);
        $this->runtime->isDir('/file')->willReturn(true);
        self::assertTrue($this->fs->exists('/file'));
    }

    public function testExistsShouldReturnTrueIfItsALink(): void
    {
        $this->runtime->isFile('/file')->willReturn(false);
        $this->runtime->isDir('/file')->willReturn(false);
        $this->runtime->isLink('/file')->willReturn(true);
        self::assertTrue($this->fs->exists('/file'));
    }

    public function testReadShouldThrowIfNotAFile(): void
    {
        $this->runtime->isFile('/file')->willReturn(false);

        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('File "file" does not exist or is not readable');
        $this->fs->read('file');
    }

    public function testReadShouldThrowIfNotReadable(): void
    {
        $this->runtime->isFile('/file')->willReturn(true);
        $this->runtime->isReadable('/file')->willReturn(false);

        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('File "file" does not exist or is not readable');
        $this->fs->read('file');
    }

    public function testReadShouldThrowFOpenFails(): void
    {
        $this->runtime->isFile('/file')->willReturn(true);
        $this->runtime->isReadable('/file')->willReturn(true);

        $setError = static function () {
            self::$lastError = [
                'message' => 'Error',
                'file' => __FILE__,
                'line' => 42,
                'type' => E_USER_ERROR,
            ];
        };

        $this->runtime->clearLastError()->shouldBeCalled();
        $this->runtime->fopen('/file', 'rb')
            ->shouldBeCalled()
            ->will(function () use ($setError) {
                $setError();
                return false;
            });

        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('File "file" cannot be opened for read: Error');
        $this->fs->read('file');
    }

    public function testReadShouldReturnAReadableStream(): void
    {
        $this->runtime->isFile('/file')->willReturn(true);
        $this->runtime->isReadable('/file')->willReturn(true);

        $this->runtime->clearLastError()->shouldBeCalled();
        $this->runtime->fopen('/file', 'rb')
            ->shouldBeCalled()
            ->will(function () {
                $h = fopen('php://temp', 'rb+');
                fwrite($h, 'TEST TEST');
                rewind($h);

                return $h;
            });

        $ret = $this->fs->read('file');
        self::assertEquals('TEST TEST', $ret->read(100));
    }

    public function testListShouldThrowIfNotADirectory(): void
    {
        $this->runtime->isDir(__DIR__.'/../../data')->willReturn(true);
        $this->runtime->isDir(__DIR__.'/../../data/DeepFolder')->willReturn(false);
        $this->fs = new LocalFilesystem(__DIR__.'/../../data', [], $this->runtime->reveal());

        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('Directory "/DeepFolder" does not exist');
        $this->fs->list('/DeepFolder', false);
    }

    public function testListShouldListFilesNotDeeply(): void
    {
        $this->runtime->isDir(__DIR__.'/../../data')->willReturn(true);
        $this->fs = new LocalFilesystem(__DIR__.'/../../data', [], $this->runtime->reveal());
        $list = $this->fs->list('/', false);

        self::assertCount(4, $list);
    }

    public function testListShouldListFilesDeeply(): void
    {
        $this->runtime->isDir(__DIR__.'/../../data')->willReturn(true);
        $this->fs = new LocalFilesystem(__DIR__.'/../../data', [], $this->runtime->reveal());
        $list = $this->fs->list('/', true);

        self::assertCount(8, $list);
    }

    public function testStatShouldThrowIfFileDoesNotExist(): void
    {
        $this->runtime->isFile(__DIR__.'/../../data/NON_EXISTENT_FILE')->willReturn(false);
        $this->runtime->isDir(__DIR__.'/../../data/NON_EXISTENT_FILE')->willReturn(false);
        $this->runtime->isLink(__DIR__.'/../../data/NON_EXISTENT_FILE')->willReturn(false);

        $this->runtime->isDir(__DIR__.'/../../data')->willReturn(true);
        $this->fs = new LocalFilesystem(__DIR__.'/../../data', [], $this->runtime->reveal());

        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('Stat failed for NON_EXISTENT_FILE: does not exist');

        $this->fs->stat('NON_EXISTENT_FILE');
    }

    public function testWriteShouldSetDefaultFilePermissions(): void
    {
        $handle = fopen('php://temp', 'wb+');
        $this->runtime->fopen('/TEST_WRITE', 'xb')->willReturn($handle);
        $this->runtime->chmod('/TEST_WRITE', 0644)->shouldBeCalled();
        $this->runtime->fclose($handle)->shouldBeCalled();
        $this->runtime->mkdir('/', 0755, true)->willReturn(true);

        $this->fs->write('/TEST_WRITE', 'Test content');

        rewind($handle);
        self::assertEquals('Test content', fread($handle, 512));
    }

    public function testWriteShouldNotSetFilePermissionsIfFileExists(): void
    {
        $handle = fopen('php://temp', 'wb+');
        $this->runtime->fopen('/TEST_WRITE', 'xb')->willReturn(false);
        $this->runtime->fopen('/TEST_WRITE', 'wb')->willReturn($handle);
        $this->runtime->chmod('/TEST_WRITE', Argument::cetera())->shouldNotBeCalled();
        $this->runtime->fclose($handle)->shouldBeCalled();
        $this->runtime->mkdir('/', 0755, true)->willReturn(true);

        $this->fs->write('/TEST_WRITE', 'Test content');

        rewind($handle);
        self::assertEquals('Test content', fread($handle, 512));
    }

    public function testWriteShouldSetFilePermissionsIfFileExistsAndIsPassedAsThirdArgument(): void
    {
        $handle = fopen('php://temp', 'wb+');
        $this->runtime->fopen('/TEST_WRITE', 'xb')->willReturn(false);
        $this->runtime->fopen('/TEST_WRITE', 'wb')->willReturn($handle);
        $this->runtime->chmod('/TEST_WRITE', 0600)->shouldBeCalled();
        $this->runtime->fclose($handle)->shouldBeCalled();
        $this->runtime->mkdir('/', 0755, true)->willReturn(true);

        $this->fs->write('/TEST_WRITE', 'Test content', [
            'local' => [
                'file_permissions' => 0600,
            ],
        ]);

        rewind($handle);
        self::assertEquals('Test content', fread($handle, 512));
    }

    public function testWriteShouldShouldThrowIfFileCannotBeOpened(): void
    {
        $setError = static function () {
            self::$lastError = [
                'message' => 'Error',
                'file' => __FILE__,
                'line' => 42,
                'type' => E_USER_ERROR,
            ];
        };

        $this->runtime->fopen('/TEST_WRITE', 'xb')->willReturn(false);
        $this->runtime->mkdir('/', 0755, true)->willReturn(true);
        $this->runtime->fopen('/TEST_WRITE', 'wb')->will(function () use ($setError) {
            $setError();
            return false;
        });

        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('Unable to open file "/TEST_WRITE" for writing: Error');

        $this->fs->write('/TEST_WRITE', 'Test content', [
            'local' => [
                'file_permissions' => 0600,
            ],
        ]);
    }

    public function testWriteShouldCreateFileWithPermissionsIfFileNotExists(): void
    {
        $handle = fopen('php://temp', 'wb+');
        $this->runtime->fopen('/TEST_WRITE', 'xb')->willReturn($handle);
        $this->runtime->chmod('/TEST_WRITE', 0400)->shouldBeCalled();
        $this->runtime->fclose($handle)->shouldBeCalled();
        $this->runtime->mkdir('/', 0755, true)->willReturn(true);

        $this->fs->write('/TEST_WRITE', 'Test content', [
            'local' => [
                'file_permissions' => 0400,
            ],
        ]);

        rewind($handle);
        self::assertEquals('Test content', fread($handle, 512));
    }

    public function testDeleteShouldUnlinkAFile(): void
    {
        $this->runtime->isFile('/TEST_FILE')->willReturn(true);
        $this->runtime->unlink('/TEST_FILE')->shouldBeCalled()->willReturn(true);

        $this->fs->delete('TEST_FILE');
    }

    public function testDeleteShouldUnlinkALink(): void
    {
        $this->runtime->isFile('/TEST_FILE')->willReturn(false);
        $this->runtime->isLink('/TEST_FILE')->willReturn(true);
        $this->runtime->unlink('/TEST_FILE')->shouldBeCalled()->willReturn(true);

        $this->fs->delete('TEST_FILE');
    }

    public function testDeleteShouldThrowIfNotAFileOrLink(): void
    {
        $this->runtime->isFile('/TEST_FILE')->willReturn(false);
        $this->runtime->isLink('/TEST_FILE')->willReturn(false);

        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('Cannot remove file "TEST_FILE": not a file');
        $this->fs->delete('TEST_FILE');
    }

    public function testDeleteShouldThrowIfUnlinkFails(): void
    {
        $setError = static function () {
            self::$lastError = [
                'message' => 'Error',
                'file' => __FILE__,
                'line' => 42,
                'type' => E_USER_ERROR,
            ];
        };

        $this->runtime->isFile('/TEST_FILE')->willReturn(true);
        $this->runtime->unlink('/TEST_FILE')
            ->shouldBeCalled()
            ->will(function () use ($setError) {
                $setError();
                return false;
            });

        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('Cannot remove "TEST_FILE": Error');
        $this->fs->delete('TEST_FILE');
    }

    public function testDeleteDirectoryShouldRecursivelyDeleteAllTheFiles(): void
    {
        $this->runtime->isDir(__DIR__.'/../../data')->willReturn(true);
        $this->runtime->isDir(__DIR__.'/../../data/DeepFolder')->willReturn(true);
        $this->fs = new LocalFilesystem(__DIR__.'/../../data', [], $this->runtime->reveal());

        $calls = [];
        $deleteCall = function ($args) use (&$calls) {
            $calls[] = $args[0];
            return true;
        };

        $this->runtime->unlink(__DIR__.'/../../data/DeepFolder/DoubleDeep/DOUBLE_DEEP_FILE')->shouldBeCalled()->will($deleteCall);
        $this->runtime->unlink(__DIR__.'/../../data/DeepFolder/DoubleDeep/TEST_FILE')->shouldBeCalled()->will($deleteCall);
        $this->runtime->rmdir(__DIR__.'/../../data/DeepFolder/DoubleDeep')->shouldBeCalled()->will($deleteCall);
        $this->runtime->unlink(__DIR__.'/../../data/DeepFolder/DEEP_FILE')->shouldBeCalled()->will($deleteCall);
        $this->runtime->rmdir(__DIR__.'/../../data/DeepFolder')->shouldBeCalled()->will($deleteCall);

        $this->fs->deleteDirectory('DeepFolder');

        self::assertEquals([
            __DIR__.'/../../data/DeepFolder/DEEP_FILE',
            __DIR__.'/../../data/DeepFolder/DoubleDeep/DOUBLE_DEEP_FILE',
            __DIR__.'/../../data/DeepFolder/DoubleDeep/TEST_FILE',
            __DIR__.'/../../data/DeepFolder/DoubleDeep',
            __DIR__.'/../../data/DeepFolder',
        ], $calls);
    }

    public function testDeleteDirectoryShouldThrowIfRmdirFails(): void
    {
        $this->runtime->isDir(__DIR__.'/../../data')->willReturn(true);
        $this->runtime->isDir(__DIR__.'/../../data/DeepFolder')->willReturn(true);
        $this->fs = new LocalFilesystem(__DIR__.'/../../data', [], $this->runtime->reveal());

        $this->runtime->unlink(Argument::any())->shouldBeCalled()->willReturn(true);
        $this->runtime->rmdir(Argument::any())->shouldBeCalled()->willReturn(true);

        $setError = static function () {
            self::$lastError = [
                'message' => 'Error',
                'file' => __FILE__,
                'line' => 42,
                'type' => E_USER_ERROR,
            ];
        };

        $this->runtime->rmdir(__DIR__.'/../../data/DeepFolder')
            ->shouldBeCalled()
            ->will(function () use ($setError) {
                $setError();
                return false;
            });

        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('Unable to delete directory "DeepFolder": Error');

        $this->fs->deleteDirectory('DeepFolder');
    }

    public function testDeleteDirectoryShouldThrowIfNotADirectory(): void
    {
        $this->runtime->isDir('/DeepFolder')->willReturn(false);

        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('Unable to delete directory "DeepFolder": not a directory');

        $this->fs->deleteDirectory('DeepFolder');
    }

    public function testDeleteDirectoryShouldThrowIfOneOfTheNestedFilesFailsToBeRemoved(): void
    {
        $this->runtime->isDir(__DIR__.'/../../data')->willReturn(true);
        $this->runtime->isDir(__DIR__.'/../../data/DeepFolder')->willReturn(true);
        $this->fs = new LocalFilesystem(__DIR__.'/../../data', [], $this->runtime->reveal());

        $setError = static function () {
            self::$lastError = [
                'message' => 'Error',
                'file' => __FILE__,
                'line' => 42,
                'type' => E_USER_ERROR,
            ];
        };

        $this->runtime->unlink(Argument::type('string'))->shouldBeCalled()->willReturn(true);
        $this->runtime->unlink(__DIR__.'/../../data/DeepFolder/DoubleDeep/TEST_FILE')->shouldBeCalled()->will(function () use ($setError) {
            $setError();
            return false;
        });

        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('Unable to delete directory: unable to delete file "' . __DIR__ . '/../../data/DeepFolder/DoubleDeep/TEST_FILE": Error');

        $this->fs->deleteDirectory('DeepFolder');
    }

    public function testCreateDirectoryShouldDoNothingIfDirAlreadyExist(): void
    {
        $this->expectNotToPerformAssertions();

        $this->runtime->isDir('/folder')->willReturn(true);
        $this->fs->createDirectory('folder');
    }

    public function testCreateDirectoryShouldModifyDirPermissionsIfSecondArgumentIsGiven(): void
    {
        $this->runtime->isDir('/folder')->willReturn(true);
        $this->runtime->chmod('/folder', 0755)
            ->shouldBeCalled()
            ->willReturn(true);

        $this->fs->createDirectory('folder', [
            'local' => [
                'dir_permissions' => 0755,
            ],
        ]);
    }

    public function testCreateDirectoryShouldCreateDirectoryWithCorrectPermissions(): void
    {
        $this->runtime->isDir('/folder')->willReturn(false);
        $this->runtime->mkdir('/folder', 0755, true)
            ->shouldBeCalled()
            ->willReturn(true);

        $this->fs->createDirectory('folder', [
            'local' => [
                'dir_permissions' => 0755,
            ],
        ]);
    }

    public function testCreateDirectoryShouldThrowIfMkdirFails(): void
    {
        $setError = static function () {
            self::$lastError = [
                'message' => 'Error',
                'file' => __FILE__,
                'line' => 42,
                'type' => E_USER_ERROR,
            ];
        };

        $this->runtime->isDir('/folder')->willReturn(false);
        $this->runtime->mkdir('/folder', 0755, true)
            ->shouldBeCalled()
            ->will(function () use ($setError) {
                $setError();
                return false;
            });

        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('Unable to create directory: Error');

        $this->fs->createDirectory('folder');
    }

    public function testMoveShouldThrowIfSourceDoesNotExist(): void
    {
        $this->runtime->isFile('/folder')->willReturn(false);
        $this->runtime->isDir('/folder')->willReturn(false);
        $this->runtime->isLink('/folder')->willReturn(false);

        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('Cannot move file: source does not exist');
        $this->fs->move('folder', 'new_folder');
    }

    public function testMoveShouldThrowIfDestinationAlreadyExist(): void
    {
        $this->runtime->isFile('/folder')->willReturn(false);
        $this->runtime->isDir('/folder')->willReturn(true);
        $this->runtime->isFile('/new_folder')->willReturn(false);
        $this->runtime->isDir('/new_folder')->willReturn(true);

        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('Cannot move file: destination already exist and overwrite flag is not set');
        $this->fs->move('folder', 'new_folder');
    }

    public function testMoveShouldEnsureParentDirExists(): void
    {
        $this->runtime->isFile('/folder')->willReturn(false);
        $this->runtime->isDir('/folder')->willReturn(true);
        $this->runtime->isFile('/new_folder/dest/path')->willReturn(false);
        $this->runtime->isDir('/new_folder/dest/path')->willReturn(false);
        $this->runtime->isLink('/new_folder/dest/path')->willReturn(false);

        $calls = 0;
        $this->runtime->isDir('/new_folder/dest')->will(function () use (&$calls): bool {
            return $calls++ > 0;
        });
        $this->runtime->mkdir('/new_folder/dest', 0700, true)->shouldBeCalled()->willReturn(true);
        $this->runtime->rename('/folder', '/new_folder/dest/path')->willReturn(true);

        $this->fs->move('folder', 'new_folder/dest/path', [
            'local' => [
                'dir_permissions' => 0700,
            ],
        ]);
    }

    public function testMoveShouldThrowIfRenameFails(): void
    {
        $this->runtime->isFile('/folder')->willReturn(false);
        $this->runtime->isDir('/folder')->willReturn(true);
        $this->runtime->isFile('/new_folder/dest/path')->willReturn(false);
        $this->runtime->isDir('/new_folder/dest/path')->willReturn(false);
        $this->runtime->isLink('/new_folder/dest/path')->willReturn(false);

        $setError = static function () {
            self::$lastError = [
                'message' => 'Error',
                'file' => __FILE__,
                'line' => 42,
                'type' => E_USER_ERROR,
            ];
        };

        $this->runtime->isDir('/new_folder/dest')->willReturn(true);
        $this->runtime->rename('/folder', '/new_folder/dest/path')->will(function () use ($setError) {
            $setError();
            return false;
        });

        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('Unable to move file: Error');

        $this->fs->move('folder', 'new_folder/dest/path', [
            'local' => [
                'dir_permissions' => 0700,
            ],
        ]);
    }

    public function testMoveShouldApplyCorrectPermissions(): void
    {
        $this->runtime->isFile('/folder')->willReturn(false);
        $this->runtime->isDir('/folder')->willReturn(true);
        $this->runtime->isFile('/new_folder/dest/path')->willReturn(false);
        $calls = 0;
        $this->runtime->isDir('/new_folder/dest/path')->will(function () use (&$calls) {
            return $calls++ > 0;
        });
        $this->runtime->isLink('/new_folder/dest/path')->willReturn(false);

        $this->runtime->isDir('/new_folder/dest')->willReturn(true);
        $this->runtime->rename('/folder', '/new_folder/dest/path')->willReturn(true);

        $this->runtime->chmod('/new_folder/dest/path', 0700)->shouldBeCalled()->willReturn(true);

        $this->fs->move('folder', 'new_folder/dest/path', [
            'local' => [
                'dir_permissions' => 0700,
            ],
        ]);
    }

    public function testCopyShouldThrowIfSourceDoesNotExist(): void
    {
        $this->runtime->isFile('/folder')->willReturn(false);
        $this->runtime->isDir('/folder')->willReturn(false);
        $this->runtime->isLink('/folder')->willReturn(false);

        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('Cannot copy file: source does not exist');
        $this->fs->copy('folder', 'new_folder');
    }

    public function testCopyShouldThrowIfDestinationAlreadyExist(): void
    {
        $this->runtime->isFile('/folder')->willReturn(false);
        $this->runtime->isDir('/folder')->willReturn(true);
        $this->runtime->isFile('/new_folder')->willReturn(false);
        $this->runtime->isDir('/new_folder')->willReturn(true);

        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('Cannot copy file: destination already exist and overwrite flag is not set');
        $this->fs->copy('folder', 'new_folder');
    }

    public function testCopyShouldEnsureParentDirExists(): void
    {
        $this->runtime->isFile('/folder')->willReturn(false);
        $this->runtime->isDir('/folder')->willReturn(true);
        $this->runtime->isFile('/new_folder/dest/path')->willReturn(false);
        $this->runtime->isDir('/new_folder/dest/path')->willReturn(false);
        $this->runtime->isLink('/new_folder/dest/path')->willReturn(false);

        $calls = 0;
        $this->runtime->isDir('/new_folder/dest')->will(function () use (&$calls): bool {
            return $calls++ > 0;
        });
        $this->runtime->mkdir('/new_folder/dest', 0700, true)->shouldBeCalled()->willReturn(true);
        $this->runtime->copy('/folder', '/new_folder/dest/path')->willReturn(true);

        $this->fs->copy('folder', 'new_folder/dest/path', [
            'local' => [
                'dir_permissions' => 0700,
            ],
        ]);
    }

    public function testCopyShouldThrowIfCopyFails(): void
    {
        $this->runtime->isFile('/folder')->willReturn(false);
        $this->runtime->isDir('/folder')->willReturn(true);
        $this->runtime->isFile('/new_folder/dest/path')->willReturn(false);
        $this->runtime->isDir('/new_folder/dest/path')->willReturn(false);
        $this->runtime->isLink('/new_folder/dest/path')->willReturn(false);

        $setError = static function () {
            self::$lastError = [
                'message' => 'Error',
                'file' => __FILE__,
                'line' => 42,
                'type' => E_USER_ERROR,
            ];
        };

        $this->runtime->isDir('/new_folder/dest')->willReturn(true);
        $this->runtime->copy('/folder', '/new_folder/dest/path')->will(function () use ($setError) {
            $setError();
            return false;
        });

        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('Unable to copy file: Error');

        $this->fs->copy('folder', 'new_folder/dest/path', [
            'local' => [
                'dir_permissions' => 0700,
            ],
        ]);
    }

    public function testCopyShouldApplyCorrectPermissions(): void
    {
        $this->runtime->isFile('/folder')->willReturn(false);
        $this->runtime->isDir('/folder')->willReturn(true);
        $this->runtime->isFile('/new_folder/dest/path')->willReturn(false);
        $calls = 0;
        $this->runtime->isDir('/new_folder/dest/path')->will(function () use (&$calls) {
            return $calls++ > 0;
        });
        $this->runtime->isLink('/new_folder/dest/path')->willReturn(false);

        $this->runtime->isDir('/new_folder/dest')->willReturn(true);
        $this->runtime->copy('/folder', '/new_folder/dest/path')->willReturn(true);

        $this->runtime->chmod('/new_folder/dest/path', 0700)->shouldBeCalled()->willReturn(true);

        $this->fs->copy('folder', 'new_folder/dest/path', [
            'local' => [
                'dir_permissions' => 0700,
            ],
        ]);
    }
}
