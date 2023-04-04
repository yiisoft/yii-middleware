<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Middleware\Tests;

use Yiisoft\Yii\Middleware\Exception\BadUriPrefixException;
use PHPUnit\Framework\TestCase;

class BadUriPrefixExceptionTest extends TestCase
{
    public function testException(): void
    {
        $exception = new BadUriPrefixException('Bad URI detected');
        $this->assertSame('Bad URI prefix', $exception->getName());
        $this->assertNotEmpty($exception->getSolution());
    }
}
