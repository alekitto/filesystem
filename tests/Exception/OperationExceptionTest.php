<?php

declare(strict_types=1);

namespace Tests\Exception;

use Kcs\Filesystem\Exception\OperationException;
use PHPUnit\Framework\TestCase;

class OperationExceptionTest extends TestCase
{
    public function testMessageOfPreviousErrorIsAppended(): void
    {
        $ex = new OperationException('Error', new \Exception('Previous error'));
        self::assertEquals('Error: Previous error', $ex->getMessage());
        self::assertEquals(0, $ex->getCode());
    }

    public function testMessageIsLeftUntouchedIfNoPreviousErrorIsPassed(): void
    {
        $ex = new OperationException('Error');
        self::assertEquals('Error', $ex->getMessage());
        self::assertEquals(0, $ex->getCode());
    }
}
