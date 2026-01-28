<?php declare(strict_types=1);

namespace Tests;

use Kcs\Filesystem\Exception\InvalidPathException;
use Kcs\Filesystem\PathNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PathNormalizerTest extends TestCase
{
    #[DataProvider('providePaths')]
    public function testPathNormalizing(string $input, string $expected): void
    {
        $result = PathNormalizer::normalizePath($input);
        $double = PathNormalizer::normalizePath(PathNormalizer::normalizePath($input));

        self::assertEquals($expected, $result);
        self::assertEquals($expected, $double);
    }

    public static function providePaths(): iterable
    {
        yield ['.', ''];
        yield ['/path/to/dir/.', 'path/to/dir'];
        yield ['/dirname/', 'dirname'];
        yield ['dirname/..', ''];
        yield ['dirname/../', ''];
        yield ['dirname./', 'dirname.'];
        yield ['dirname/./', 'dirname'];
        yield ['dirname/.', 'dirname'];
        yield ['./dir/../././', ''];
        yield ['/something/deep/../../dirname', 'dirname'];
        yield ['00004869/files/other/10-75..stl', '00004869/files/other/10-75..stl'];
        yield ['/dirname//subdir///subsubdir', 'dirname/subdir/subsubdir'];
        yield ['\dirname\\\\subdir\\\\\\subsubdir', 'dirname/subdir/subsubdir'];
        yield ['\\\\some\shared\\\\drive', 'some/shared/drive'];
        yield ['C:\dirname\\\\subdir\\\\\\subsubdir', 'C:/dirname/subdir/subsubdir'];
        yield ['C:\\\\dirname\subdir\\\\subsubdir', 'C:/dirname/subdir/subsubdir'];
        yield ['example/path/..txt', 'example/path/..txt'];
        yield ['\\example\\path.txt', 'example/path.txt'];
        yield ['\\example\\..\\path.txt', 'path.txt'];
        yield ["some\0/path.txt", 'some/path.txt'];
    }

    #[DataProvider('provideInvalidPath')]
    public function testShouldThrowTryingToTraversePath(string $input): void
    {
        $this->expectException(InvalidPathException::class);
        $this->expectExceptionMessage('Invalid path');
        $this->expectExceptionCode(0);

        PathNormalizer::normalizePath($input);
    }

    public static function provideInvalidPath(): iterable
    {
        yield ['something/../../../hehe'];
        yield ['/something/../../..'];
        yield ['..'];
        yield ['something\\..\\..'];
        yield ['\\something\\..\\..\\dirname'];
    }
}
