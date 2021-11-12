<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Web\Tests\Middleware;

use InvalidArgumentException;
use HttpSoft\Message\ResponseFactory;
use HttpSoft\Message\ServerRequestFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Http\Method;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\Middleware\Redirect;

final class RedirectTest extends TestCase
{
    public function testInvalidArguments(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->createRedirectMiddleware()->process($this->createRequest(), $this->createRequestHandler());
    }

    public function testGenerateUri(): void
    {
        $middleware = $this->createRedirectMiddleware()->toRoute('test/route', [
            'param1' => 1,
            'param2' => 2,
        ]);

        $response = $middleware->process($this->createRequest(), $this->createRequestHandler());
        $header = $response->getHeader('Location');

        $this->assertSame($header[0], 'test/route?param1=1&param2=2');
    }

    public function testTemporaryReturnCode303(): void
    {
        $middleware = $this->createRedirectMiddleware()
            ->toRoute('test/route')
            ->temporary();

        $response = $middleware->process($this->createRequest(), $this->createRequestHandler());

        $this->assertSame($response->getStatusCode(), 303);
    }

    public function testPermanentReturnCode301(): void
    {
        $middleware = $this->createRedirectMiddleware()
            ->toRoute('test/route')
            ->permanent();

        $response = $middleware->process($this->createRequest(), $this->createRequestHandler());

        $this->assertSame($response->getStatusCode(), 301);
    }

    public function testStatusReturnCode400(): void
    {
        $middleware = $this->createRedirectMiddleware()
            ->toRoute('test/route')
            ->withStatus(400);

        $response = $middleware->process($this->createRequest(), $this->createRequestHandler());

        $this->assertSame($response->getStatusCode(), 400);
    }

    public function testSetUri(): void
    {
        $middleware = $this->createRedirectMiddleware()
            ->toUrl('test/custom/route');

        $response = $middleware->process($this->createRequest(), $this->createRequestHandler());
        $header = $response->getHeader('Location');

        $this->assertSame($header[0], 'test/custom/route');
    }

    private function createRequestHandler(): RequestHandlerInterface
    {
        $requestHandler = $this->createMock(RequestHandlerInterface::class);
        $requestHandler
            ->method('handle')
            ->willReturn((new ResponseFactory())->createResponse());

        return $requestHandler;
    }

    private function createRequest(string $method = Method::GET, string $uri = '/'): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, $uri);
    }

    private function createRedirectMiddleware(): Redirect
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator
            ->method('generate')
            ->willReturnCallback(fn ($name, $params) => $name . '?' . http_build_query($params));

        return new Redirect(new ResponseFactory(), $urlGenerator);
    }
}
