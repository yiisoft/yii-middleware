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
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('Last-Modified'));
    }

    public function testModifiedResultWithLastModified(): void
    {
        $time = time();
        $middleware = $this->createMiddlewareWithLastModified($time + 1);
        $headers = [
            'If-Modified-Since' => gmdate('D, d M Y H:i:s', $time) . 'GMT',
        ];
        $response = $middleware->process($this->createServerRequest(Method::GET, $headers), $this->createRequestHandler());
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testModifiedResultWithEtag(): void
    {
        $etag = 'test-etag';
        $middleware = $this->createMiddlewareWithETag($etag);
        $headers = [
            'If-None-Match' => $etag,
        ];
        $response = $middleware->process($this->createServerRequest(Method::GET, $headers), $this->createRequestHandler());
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals($response->getHeaderLine('Etag'), $this->generateEtag($etag));
    }

    public function testNotModifiedResultWithLastModified(): void
    {
        $time = time();
        $middleware = $this->createMiddlewareWithLastModified($time - 1);
        $headers = [
            'If-Modified-Since' => gmdate('D, d M Y H:i:s', $time) . ' GMT',
        ];
        $response = $middleware->process($this->createServerRequest(Method::GET, $headers), $this->createRequestHandler());
        $this->assertEquals(304, $response->getStatusCode());
        $this->assertEmpty((string)$response->getBody());
        $this->assertEquals(gmdate('D, d M Y H:i:s', $time - 1) . ' GMT', $response->getHeaderLine('Last-Modified'));
    }

    public function testNotModifiedResultWithEtag(): void
    {
        $etag = 'test-etag';
        $middleware = $this->createMiddlewareWithETag($etag);
        $headers = [
            'If-None-Match' => $this->generateEtag($etag),
        ];
        $response = $middleware->process($this->createServerRequest(Method::GET, $headers), $this->createRequestHandler());
        $this->assertEquals(304, $response->getStatusCode());
        $this->assertEmpty((string)$response->getBody());
    }

    private function createMiddlewareWithLastModified(int $lastModified): HttpCache
    {
        $middleware = new HttpCache();
        return $middleware->withLastModified(fn () => $lastModified);
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
