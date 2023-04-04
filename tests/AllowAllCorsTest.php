<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Middleware\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Yii\Middleware\AllowAllCors;

final class AllowAllCorsTest extends TestCase
{
    public function testProcess()
    {
        $allowAllCors = new AllowAllCors();
        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $response
            ->expects($this->any())
            ->method('withHeader')
            ->withConsecutive(
                [$this->equalTo('Allow'), $this->equalTo('*')],
                [$this->equalTo('Vary'), $this->equalTo('Origin')],
                [$this->equalTo('Access-Control-Allow-Origin'), $this->equalTo('*')],
                [
                    $this->equalTo('Access-Control-Allow-Methods'),
                    $this->equalTo('GET,OPTIONS,HEAD,POST,PUT,PATCH,DELETE'),
                ],
                [$this->equalTo('Access-Control-Allow-Headers'), $this->equalTo('*')],
                [$this->equalTo('Access-Control-Allow-Credentials'), $this->equalTo('true')],
                [$this->equalTo('Access-Control-Max-Age'), $this->equalTo('86400')]
            )
            ->willReturnSelf();
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $allowAllCors->process($request, $handler);

        $this->expectNotToPerformAssertions();
    }
}
