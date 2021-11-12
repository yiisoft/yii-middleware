<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Middleware\Tests;

use HttpSoft\Message\Response;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Http\Status;
use Yiisoft\Validator\Rule\Ip;
use Yiisoft\Yii\Middleware\IpFilter;

final class IpFilterTest extends TestCase
{
    private const REQUEST_PARAMS = [
        'REMOTE_ADDR' => '8.8.8.8',
    ];

    private const ALLOWED_IP = '1.1.1.1';

    private MockObject|ResponseFactoryInterface $responseFactoryMock;
    private MockObject|RequestHandlerInterface $requestHandlerMock;
    private IpFilter $ipFilter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->responseFactoryMock = $this->createMock(ResponseFactoryInterface::class);
        $this->requestHandlerMock = $this->createMock(RequestHandlerInterface::class);
        $this->ipFilter = new IpFilter(Ip::rule()->ranges([self::ALLOWED_IP]), $this->responseFactoryMock);
    }

    public function testProcessReturnsAccessDeniedResponseWhenIpIsNotAllowed(): void
    {
        $this->setUpResponseFactory();
        $requestMock = $this->createMock(ServerRequestInterface::class);
        $requestMock
            ->expects($this->once())
            ->method('getServerParams')
            ->willReturn(self::REQUEST_PARAMS);

        $this->requestHandlerMock
            ->expects($this->never())
            ->method('handle')
            ->with($requestMock);

        $response = $this->ipFilter->process($requestMock, $this->requestHandlerMock);
        $this->assertEquals(403, $response->getStatusCode());
    }

    public function testProcessCallsRequestHandlerWhenRemoteAddressIsAllowed(): void
    {
        $requestParams = [
            'REMOTE_ADDR' => self::ALLOWED_IP,
        ];
        $requestMock = $this->createMock(ServerRequestInterface::class);
        $requestMock
            ->expects($this->once())
            ->method('getServerParams')
            ->willReturn($requestParams);

        $this->requestHandlerMock
            ->expects($this->once())
            ->method('handle')
            ->with($requestMock);

        $this->ipFilter->process($requestMock, $this->requestHandlerMock);
    }

    private function setUpResponseFactory(): void
    {
        $response = new Response(Status::FORBIDDEN);
        $this->responseFactoryMock
            ->expects($this->once())
            ->method('createResponse')
            ->willReturn($response);
    }
}
