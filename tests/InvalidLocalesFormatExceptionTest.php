<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Middleware\Tests;

use PHPUnit\Framework\TestCase;
use Yiisoft\Yii\Middleware\Exception\InvalidLocalesFormatException;

class InvalidLocalesFormatExceptionTest extends TestCase
{
    public function testException(): void
    {
        $exception = new InvalidLocalesFormatException();
        $this->assertSame('Invalid locales format.', $exception->getName());
        $this->assertNotEmpty($exception->getSolution());
    }
}
