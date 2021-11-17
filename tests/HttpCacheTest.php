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
use function base64_encode;
use function rtrim;
use function sha1;

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

    public function testModifiedResultWithEtag(): void
    {
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
        $this->assertSame($response->getHeaderLine('Etag'), $this->generateEtag($etag));
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

    public function testNotModifiedResultWithEtag(): void
    {
        $etag = 'test-etag';
        $middleware = $this->createMiddlewareWithETag($etag);

        $headers = [
            'If-None-Match' => $this->generateEtag($etag),
        ];

        $response = $middleware->process(
            $this->createServerRequest(Method::GET, $headers),
            $this->createRequestHandler(),
        );

        $this->assertSame(Status::NOT_MODIFIED, $response->getStatusCode());
        $this->assertEmpty((string) $response->getBody());
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
        $this->assertNotSame($middleware, $middleware->withWeakTag());
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
        $requestHandler->method('handle')->willReturn(new Response(Status::OK));
        return $requestHandler;
    }

    private function createServerRequest(string $method = Method::GET, array $headers = []): ServerRequestInterface
    {
        $request =  (new ServerRequest())->withMethod($method);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    private function generateEtag(string $seed, ?string $weakEtag = null): string
    {
        $etag = '"' . rtrim(base64_encode(sha1($seed, true)), '=') . '"';
        return $weakEtag ? 'W/' . $etag : $etag;
    }
}
