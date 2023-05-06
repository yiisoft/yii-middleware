<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Middleware\Tests\Exception;

use PHPUnit\Framework\TestCase;
use Yiisoft\Yii\Middleware\Exception\BadUriPrefixException;

final class BadUriPrefixExceptionTest extends TestCase
{
    public function testGetCode(): void
    {
        $exception = new BadUriPrefixException('test');
        $this->assertSame(0, $exception->getCode());
    }

    public function testReturnTypes(): void
    {
        $exception = new BadUriPrefixException('test');
        $this->assertIsString($exception->getName());
        $this->assertIsString($exception->getSolution());
    }
}
