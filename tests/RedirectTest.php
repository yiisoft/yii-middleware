<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Web\Tests\Middleware;

use HttpSoft\Message\ResponseFactory;
use HttpSoft\Message\ServerRequestFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Yiisoft\Http\Method;
use Yiisoft\Http\Status;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\Middleware\Redirect;

use function http_build_query;

final class RedirectTest extends TestCase
{
    public function testInvalidArguments(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Either `toUrl()` or `toRoute()` method should be used.');

        $this
            ->createRedirectMiddleware()
            ->process($this->createRequest(), $this->createRequestHandler());
    }

    public function testGenerateUri(): void
    {
        $middleware = $this
            ->createRedirectMiddleware()
            ->toRoute('test/route', [
                'param1' => 1,
                'param2' => 2,
            ]);

        $response = $middleware->process($this->createRequest(), $this->createRequestHandler());
        $header = $response->getHeader('Location');

        $this->assertSame($header[0], 'test/route?param1=1&param2=2');
    }

    public function testTemporaryReturnCode302(): void
    {
        $middleware = $this
            ->createRedirectMiddleware()
            ->toRoute('test/route')
            ->temporary()
        ;

        $response = $middleware->process($this->createRequest(), $this->createRequestHandler());

        $this->assertSame($response->getStatusCode(), Status::FOUND);
    }

    public function testPermanentReturnCode301(): void
    {
        $middleware = $this
            ->createRedirectMiddleware()
            ->toRoute('test/route')
            ->permanent()
        ;

        $response = $middleware->process($this->createRequest(), $this->createRequestHandler());

        $this->assertSame($response->getStatusCode(), Status::MOVED_PERMANENTLY);
    }

    public function testStatusReturnCode400(): void
    {
        $middleware = $this
            ->createRedirectMiddleware()
            ->toRoute('test/route')
            ->withStatus(Status::BAD_REQUEST)
        ;

        $response = $middleware->process($this->createRequest(), $this->createRequestHandler());

        $this->assertSame($response->getStatusCode(), Status::BAD_REQUEST);
    }

    public function testSetUri(): void
    {
        $middleware = $this
            ->createRedirectMiddleware()
            ->toUrl('test/custom/route');

        $response = $middleware->process($this->createRequest(), $this->createRequestHandler());
        $header = $response->getHeader('Location');

        $this->assertSame($header[0], 'test/custom/route');
    }

    public function testImmutability(): void
    {
        $middleware = $this->createRedirectMiddleware();

        $this->assertNotSame($middleware, $middleware->toUrl('/'));
        $this->assertNotSame($middleware, $middleware->toRoute('test/custom/route', ['id' => 42]));
        $this->assertNotSame($middleware, $middleware->withStatus(Status::MOVED_PERMANENTLY));
        $this->assertNotSame($middleware, $middleware->permanent());
        $this->assertNotSame($middleware, $middleware->temporary());
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
