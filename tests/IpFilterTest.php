<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Middleware\Tests;

use HttpSoft\Message\Response;
use HttpSoft\Message\ResponseFactory;
use HttpSoft\Message\ServerRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Http\Status;
use Yiisoft\Http\Method;
use Yiisoft\Validator\Validator;
use Yiisoft\Yii\Middleware\IpFilter;

final class IpFilterTest extends TestCase
{
    private MockObject|ResponseFactoryInterface $responseFactoryMock;
    private MockObject|RequestHandlerInterface $requestHandlerMock;

    public static function ipNotAllowedDataProvider(): array
    {
        return [
            'basic' => [['REMOTE_ADDR' => '8.8.8.8']],
            'does not exist' => [[]],
            'empty string' => [['REMOTE_ADDR' => '']],
            'invalid IP' => [['REMOTE_ADDR' => '1']],
            'with subnet' => [['REMOTE_ADDR' => '192.168.5.32/11']],
            'with ranges' => [['10.0.0.2'], ['10.0.0.1', '!10.0.0.0/8', '!babe::/8', 'any']],
        ];
    }

    public static function noRemoteAddressDataProvider(): array
    {
        return [
            'no server params' => ['getAttribute' => null, 'serverParams' => []],
            'null remote addr' => ['getAttribute' => null, 'serverParams' => ['REMOTE_ADDR' => null]],
            'empty server params' => ['getAttribute' => null, 'serverParams' => []],
        ];
    }

    public static function dataProcessCallsRequestHandlerWhenRemoteAddressIsAllowed(): array
    {
        return [
            'basic' => ['1.1.1.1'],
            'with ranges' => ['10.0.0.1', ['10.0.0.1', '!10.0.0.0/8', '!babe::/8', 'any']],
        ];
    }

    /**
     * @dataProvider ipNotAllowedDataProvider
     *
     * @group t
     */
    public function testProcessReturnsAccessDeniedResponseWhenIpIsNotAllowed(
        array $serverParams,
        array $ipRanges = ['1.1.1.1'],
    ): void {
        $requestMock = $this->createMock(ServerRequestInterface::class);
        $requestMock
            ->expects($this->once())
            ->method('getServerParams')
            ->willReturn($serverParams);

        $this
            ->responseFactoryMock
            ->expects($this->once())
            ->method('createResponse')
            ->willReturn(new Response(Status::FORBIDDEN));

        $this
            ->requestHandlerMock
            ->expects($this->never())
            ->method('handle')
            ->with($requestMock);

        $ipFilter = new IpFilter(new Validator(), $this->responseFactoryMock, ipRanges: $ipRanges);
        $response = $ipFilter->process($requestMock, $this->requestHandlerMock);

        $this->assertSame(Status::FORBIDDEN, $response->getStatusCode());
        $this->assertSame(Status::TEXTS[Status::FORBIDDEN], (string)$response->getBody());
    }

    /**
     * @dataProvider noRemoteAddressDataProvider
     */
    public function testProcessReturnsAccessDeniedResponseWhenRemoteAddressIsMissing(
        ?string $getAttribute,
        array $serverParams,
    ): void {
        $requestMock = $this->createMock(ServerRequestInterface::class);
        $requestMock
            ->expects($this->any())
            ->method('getAttribute')
            ->willReturn($getAttribute);
        $requestMock
            ->expects($this->once())
            ->method('getServerParams')
            ->willReturn($serverParams);

        $this
            ->responseFactoryMock
            ->expects($this->once())
            ->method('createResponse')
            ->willReturn(new Response(Status::FORBIDDEN));

        $this
            ->requestHandlerMock
            ->expects($this->never())
            ->method('handle');

        $ipFilter = new IpFilter(new Validator(), $this->responseFactoryMock);
        $response = $ipFilter->process($requestMock, $this->requestHandlerMock);

        $this->assertSame(Status::FORBIDDEN, $response->getStatusCode());
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

    public function testProcessDeniesRequestWhenClientIpIsMissing(): void
    {
        $middleware = new IpFilter(new Validator(), new ResponseFactory());

        $handler = new class () implements RequestHandlerInterface {
            public bool $handled = false;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->handled = true;
                return new Response(Status::OK);
            }
        };

        $request = new ServerRequest(serverParams: [], method: Method::GET);
        $response = $middleware->process($request, $handler);

        $this->assertFalse($handler->handled);
        $this->assertSame(Status::FORBIDDEN, $response->getStatusCode());
        $this->assertSame(Status::TEXTS[Status::FORBIDDEN], (string)$response->getBody());
    }

    public function testProcessUsesClientIpAttributeWhenConfigured(): void
    {
        $middleware = new IpFilter(new Validator(), new ResponseFactory(), 'client-ip', ['10.0.0.0/8']);

        $handler = new class () implements RequestHandlerInterface {
            public bool $handled = false;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->handled = true;
                return new Response(Status::OK);
            }
        };

        $request = (new ServerRequest(serverParams: []))
            ->withMethod(Method::GET)
            ->withAttribute('client-ip', '10.10.10.10');

        $response = $middleware->process($request, $handler);

        $this->assertTrue($handler->handled);
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

        $this
            ->requestHandlerMock
            ->expects($this->once())
            ->method('handle')
            ->with($requestMock)
            ->willReturn(new Response(Status::OK));

        $ipFilter = new IpFilter(new Validator(), $this->responseFactoryMock, $attributeName, [$ip]);
        $response = $ipFilter->process($requestMock, $this->requestHandlerMock);

        $this->assertSame(Status::OK, $response->getStatusCode());
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->responseFactoryMock = $this->createMock(ResponseFactoryInterface::class);
        $this->requestHandlerMock = $this->createMock(RequestHandlerInterface::class);
    }
}
