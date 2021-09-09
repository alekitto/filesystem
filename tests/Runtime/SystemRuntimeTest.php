<?php declare(strict_types=1);

namespace Tests\Runtime;

use Kcs\Filesystem\Runtime\SystemRuntime;
use PHPUnit\Framework\TestCase;

class SystemRuntimeTest extends TestCase
{
    private SystemRuntime $runtime;

    protected function setUp(): void
    {
        $this->runtime = new SystemRuntime();
    }

    public function testIsFile(): void
    {
        self::assertTrue($this->runtime->isFile(__FILE__));
        self::assertFalse($this->runtime->isFile(__DIR__));
        self::assertFalse($this->runtime->isFile(__DIR__.'/../../data/TEST_LINK'));
    }

    public function testIsDir(): void
    {
        self::assertFalse($this->runtime->isDir(__FILE__));
        self::assertTrue($this->runtime->isDir(__DIR__));
        self::assertFalse($this->runtime->isDir(__DIR__.'/../../data/TEST_LINK'));
    }

    public function testIsLink(): void
    {
        self::assertFalse($this->runtime->isLink(__FILE__));
        self::assertFalse($this->runtime->isLink(__DIR__));
        self::assertTrue($this->runtime->isLink(__DIR__.'/../../data/TEST_LINK'));
    }

    public function testIsReadable(): void
    {
        chmod(__DIR__.'/../../data/WRITE_FILE', 0200);
        try {
            self::assertTrue($this->runtime->isReadable(__FILE__));
            self::assertFalse($this->runtime->isReadable(__DIR__.'/../../data/WRITE_FILE'));
        } finally {
            chmod(__DIR__.'/../../data/WRITE_FILE', 0600);
        }
    }

    public function testMkdir(): void
    {
        self::assertDirectoryDoesNotExist(__DIR__.'/../../data/test_dir');
        $this->runtime->mkdir(__DIR__.'/../../data/test_dir', 0755, true);
        self::assertDirectoryExists(__DIR__.'/../../data/test_dir');
    }

    /**
     * @depends testMkdir
     */
    public function testRmdir(): void
    {
        self::assertDirectoryExists(__DIR__.'/../../data/test_dir');
        $this->runtime->rmdir(__DIR__.'/../../data/test_dir');
        self::assertDirectoryDoesNotExist(__DIR__.'/../../data/test_dir');
    }
}
