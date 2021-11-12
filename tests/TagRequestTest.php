<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Middleware\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Yii\Middleware\TagRequest;

final class TagRequestTest extends TestCase
{
    public function testProcess(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);

        $request
            ->expects($this->once())
            ->method('withAttribute')
            ->with(
                $this->equalTo('requestTag'),
                $this->isType('string')
            )
            ->willReturnSelf();

        $handler = $this->createMock(RequestHandlerInterface::class);

        (new TagRequest())->process($request, $handler);
    }
}
