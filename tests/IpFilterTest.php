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
    private MockObject|ResponseFactoryInterface $responseFactoryMock;
    private MockObject|RequestHandlerInterface $requestHandlerMock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->responseFactoryMock = $this->createMock(ResponseFactoryInterface::class);
        $this->requestHandlerMock = $this->createMock(RequestHandlerInterface::class);
    }

    public function ipNotAllowedDataProvider(): array
    {
        return [
            'basic' => [['REMOTE_ADDR' => '8.8.8.8']],
            'does not exist' => [[]],
            'empty string' => [['REMOTE_ADDR' => '']],
            'with subnet' => [['REMOTE_ADDR' => '192.168.5.32/11']],
            'with negation' => [['REMOTE_ADDR' => '!192.168.5.32/32']],
            'with ranges' => [['REMOTE_ADDR' => '10.0.0.1'], ['10.0.0.1', '!10.0.0.0/8', '!babe::/8', 'any']],
        ];
    }

    /**
     * @dataProvider ipNotAllowedDataProvider
     *
     * @group t
     */
    public function testProcessReturnsAccessDeniedResponseWhenIpIsNotAllowed(array $serverParams): void
    {
        $requestMock = $this->createMock(ServerRequestInterface::class);
        $requestMock
            ->expects($this->once())
            ->method('getServerParams')
            ->willReturn($serverParams);

        $this->responseFactoryMock
            ->expects($this->once())
            ->method('createResponse')
            ->willReturn(new Response(Status::FORBIDDEN));

        $this->requestHandlerMock
            ->expects($this->never())
            ->method('handle')
            ->with($requestMock);

        $ipFilter = new IpFilter(new Validator(), $this->responseFactoryMock, ipRanges: ['1.1.1.1']);
        $response = $ipFilter->process($requestMock, $this->requestHandlerMock);

        $this->assertSame(Status::FORBIDDEN, $response->getStatusCode());
        $this->assertSame(Status::TEXTS[Status::FORBIDDEN], (string) $response->getBody());
    }

    public function dataProcessCallsRequestHandlerWhenRemoteAddressIsAllowed(): array
    {
        return [
            'basic' => ['1.1.1.1'],
            'with ranges' => ['10.0.0.1', ['10.0.0.1', '!10.0.0.0/8', '!babe::/8', 'any']],
        ];
    }

    /**
     * @dataProvider dataProcessCallsRequestHandlerWhenRemoteAddressIsAllowed
     */
    public function testProcessCallsRequestHandlerWhenRemoteAddressIsAllowed(string $ip, ?array $ipRanges = null): void
    {
        $requestMock = $this->createMock(ServerRequestInterface::class);

        $requestMock
            ->expects($this->once())
            ->method('getServerParams')
            ->willReturn(['REMOTE_ADDR' => $ip]);

        $this
            ->requestHandlerMock
            ->expects($this->once())
            ->method('handle')
            ->with($requestMock)
            ->willReturn(new Response(Status::OK));

        $ipFilter = new IpFilter(new Validator(), $this->responseFactoryMock, ipRanges: $ipRanges ?? [$ip]);
        $response = $ipFilter->process($requestMock, $this->requestHandlerMock);

        $this->assertSame(Status::OK, $response->getStatusCode());
    }

    public function testProcessCallsRequestHandlerWithSetClientIpAttribute(): void
    {
        $requestMock = $this->createMock(ServerRequestInterface::class);
        $attributeName = 'test';
        $ip = '1.1.1.1';

        $requestMock
            ->expects($this->once())
            ->method('getAttribute')
            ->with($attributeName)
            ->willReturn($ip);

        $this->requestHandlerMock
            ->expects($this->once())
            ->method('handle')
            ->with($requestMock)
            ->willReturn(new Response(Status::OK));

        $ipFilter = new IpFilter(new Validator(), $this->responseFactoryMock, $attributeName, [$ip]);
        $response = $ipFilter->process($requestMock, $this->requestHandlerMock);

        $this->assertSame(Status::OK, $response->getStatusCode());
    }
}
