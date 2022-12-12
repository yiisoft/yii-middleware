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
use Yiisoft\Validator\Validator;
use Yiisoft\Yii\Middleware\IpFilter;

final class IpFilterTest extends TestCase
{
    private const ALLOWED_IP = '1.1.1.1';

    private MockObject|ResponseFactoryInterface $responseFactoryMock;
    private MockObject|RequestHandlerInterface $requestHandlerMock;
    private IpFilter $ipFilter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->responseFactoryMock = $this->createMock(ResponseFactoryInterface::class);
        $this->requestHandlerMock = $this->createMock(RequestHandlerInterface::class);
        $this->ipFilter = new IpFilter(new Validator(), $this->responseFactoryMock, null, [self::ALLOWED_IP]);
    }

    public function ipNotAllowedDataProvider(): array
    {
        return [
            'not-allowed' => [['REMOTE_ADDR' => '8.8.8.8']],
            'not-exists' => [[]],
        ];
    }

    /**
     * @dataProvider ipNotAllowedDataProvider
     * @group t
     */
    public function testProcessReturnsAccessDeniedResponseWhenIpIsNotAllowed(array $serverParams): void
    {
        $requestMock = $this->createMock(ServerRequestInterface::class);
        $requestMock
            ->expects($this->once())
            ->method('getServerParams')
            ->willReturn($serverParams)
        ;

        $this->responseFactoryMock
            ->expects($this->once())
            ->method('createResponse')
            ->willReturn(new Response(Status::FORBIDDEN))
        ;

        $this->requestHandlerMock
            ->expects($this->never())
            ->method('handle')
            ->with($requestMock)
        ;

        $response = $this->ipFilter->process($requestMock, $this->requestHandlerMock);

        $this->assertSame(Status::FORBIDDEN, $response->getStatusCode());
    }

    public function testProcessCallsRequestHandlerWhenRemoteAddressIsAllowed(): void
    {
        $requestMock = $this->createMock(ServerRequestInterface::class);

        $requestMock
            ->expects($this->once())
            ->method('getServerParams')
            ->willReturn(['REMOTE_ADDR' => self::ALLOWED_IP])
        ;

        $this->requestHandlerMock
            ->expects($this->once())
            ->method('handle')
            ->with($requestMock)
            ->willReturn(new Response(Status::OK))
        ;

        $response = $this->ipFilter->process($requestMock, $this->requestHandlerMock);

        $this->assertSame(Status::OK, $response->getStatusCode());
    }

    public function testProcessCallsRequestHandlerWithSetClientIpAttribute(): void
    {
        $requestMock = $this->createMock(ServerRequestInterface::class);
        $attributeName = 'test';

        $requestMock
            ->expects($this->once())
            ->method('getAttribute')
            ->with($attributeName)
            ->willReturn(self::ALLOWED_IP)
        ;

        $this->requestHandlerMock
            ->expects($this->once())
            ->method('handle')
            ->with($requestMock)
            ->willReturn(new Response(Status::OK))
        ;

        $ipFilter = new IpFilter(new Validator(), $this->responseFactoryMock, $attributeName, [self::ALLOWED_IP]);
        $response = $ipFilter->process($requestMock, $this->requestHandlerMock);

        $this->assertSame(Status::OK, $response->getStatusCode());
    }
}
