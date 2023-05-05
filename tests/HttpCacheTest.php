<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Middleware\Tests;

use HttpSoft\Message\Response;
use HttpSoft\Message\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Http\Method;
use Yiisoft\Http\Status;
use Yiisoft\Yii\Middleware\HttpCache;

use function gmdate;
use function time;

final class HttpCacheTest extends TestCase
{
    public function testNotCacheableMethods(): void
    {
        $time = time();
        $middleware = $this->createMiddlewareWithLastModified($time + 1);
        $response = $middleware->process($this->createServerRequest(Method::PATCH), $this->createRequestHandler());

        $this->assertSame(Status::OK, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('Last-Modified'));
    }

    public function testModifiedResultWithLastModified(): void
    {
        $time = time();
        $middleware = $this->createMiddlewareWithLastModified($time + 1);

        $headers = [
            'If-Modified-Since' => gmdate('D, d M Y H:i:s', $time) . 'GMT',
        ];

        $response = $middleware->process(
            $this->createServerRequest(Method::GET, $headers),
            $this->createRequestHandler(),
        );

        $this->assertSame(Status::OK, $response->getStatusCode());
    }

    public function testResultWithEtag(): void
    {
        // Modified result

        $etag = 'test-etag';
        $middleware = $this->createMiddlewareWithETag($etag);

        $headers = [
            'If-None-Match' => $etag,
        ];

        $response = $middleware->process(
            $this->createServerRequest(Method::GET, $headers),
            $this->createRequestHandler(),
        );

        $this->assertSame(Status::OK, $response->getStatusCode());

        $etagHeaderValue = '"IMPoQ2/Us52fJk3jpOZtEACPlVA"';
        $this->assertSame($response->getHeaderLine('Etag'), $etagHeaderValue);

        // Not modified result

        $headers = [
            'If-None-Match' => $etagHeaderValue,
        ];

        $response = $middleware->process(
            $this->createServerRequest(Method::GET, $headers),
            $this->createRequestHandler(),
        );

        $this->assertSame(Status::NOT_MODIFIED, $response->getStatusCode());
        $this->assertEmpty((string) $response->getBody());
    }

    public function testNotModifiedResultWithLastModified(): void
    {
        $time = time();
        $middleware = $this->createMiddlewareWithLastModified($time - 1);

        $headers = [
            'If-Modified-Since' => gmdate('D, d M Y H:i:s', $time) . ' GMT',
        ];

        $response = $middleware->process(
            $this->createServerRequest(Method::GET, $headers),
            $this->createRequestHandler(),
        );

        $this->assertSame(Status::NOT_MODIFIED, $response->getStatusCode());
        $this->assertEmpty((string) $response->getBody());
        $this->assertSame(gmdate('D, d M Y H:i:s', $time - 1) . ' GMT', $response->getHeaderLine('Last-Modified'));
    }

    public function testEmptyIfNoneMatchAndIfModifiedSinceHeaders(): void
    {
        $middleware = (new HttpCache())
            ->withEtagSeed(static fn () => 'test-etag')
            ->withLastModified(static fn () => time() + 3600)
        ;

        $response = $middleware->process($this->createServerRequest(), $this->createRequestHandler());

        $this->assertSame(Status::OK, $response->getStatusCode());
        $this->assertEmpty((string) $response->getBody());
    }

    public function testImmutability(): void
    {
        $middleware = new HttpCache();

        $this->assertNotSame($middleware, $middleware->withLastModified(static fn () => 3600));
        $this->assertNotSame($middleware, $middleware->withEtagSeed(static fn () => 'test-etag'));
        $this->assertNotSame($middleware, $middleware->withWeakEtag());
        $this->assertNotSame($middleware, $middleware->withParams(['key' => 'value']));
        $this->assertNotSame($middleware, $middleware->withCacheControlHeader('public, max-age=3600'));
    }

    private function createMiddlewareWithLastModified(int $lastModified): HttpCache
    {
        $middleware = new HttpCache();
        return $middleware->withLastModified(static fn () => $lastModified);
    }

    private function createMiddlewareWithETag(string $etag): HttpCache
    {
        $middleware = new HttpCache();
        return $middleware->withEtagSeed(fn () => $etag);
    }

    private function createRequestHandler(): RequestHandlerInterface
    {
        $requestHandler = $this->createMock(RequestHandlerInterface::class);
        $requestHandler
            ->method('handle')
            ->willReturn(new Response(Status::OK));
        return $requestHandler;
    }

    private function createServerRequest(string $method = Method::GET, array $headers = []): ServerRequestInterface
    {
        $request = (new ServerRequest())->withMethod($method);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }
}
