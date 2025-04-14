<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Middleware\Tests;

use HttpSoft\Message\Response;
use HttpSoft\Message\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Http\Header;
use Yiisoft\Http\Method;
use Yiisoft\Http\Status;
use Yiisoft\Yii\Middleware\HttpCache;

use function gmdate;
use function time;

final class HttpCacheTest extends TestCase
{
    public static function dataModifiedResultWithEtag(): array
    {
        $etagSeed = 'test-etag';
        $expectedEtag = '"IMPoQ2/Us52fJk3jpOZtEACPlVA"';

        return [
            [$etagSeed, $etagSeed, $expectedEtag],
            [$etagSeed, '', $expectedEtag],
        ];
    }

    public static function dataNotModifiedResultWithEtag(): array
    {
        $etagSeed = 'test-etag';
        $etagValue = 'IMPoQ2/Us52fJk3jpOZtEACPlVA';

        return [
            [$etagSeed, "\"$etagValue\""],
            [$etagSeed, "\"$etagValue-gzip\""],
        ];
    }

    public static function dataNotModifiedResultWithLastModified(): array
    {
        $time = time();
        $ifModifiedSince = self::formatTime($time);

        return [
            [
                $time - 1,
                null,
                $ifModifiedSince,
                self::formatTime($time - 1),
            ],
            [
                $time - 1,
                'test-etag',
                $ifModifiedSince,
                '',
            ],
            [
                $time,
                'test-etag',
                $ifModifiedSince,
                '',
            ],
        ];
    }

    private static function formatTime(int $time): string
    {
        return gmdate('D, d M Y H:i:s', $time) . ' GMT';
    }

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
            Header::IF_MODIFIED_SINCE => $this->formatTime($time - 1),
        ];

        $response = $middleware->process(
            $this->createServerRequest(Method::GET, $headers),
            $this->createRequestHandler(),
        );

        $this->assertSame(Status::OK, $response->getStatusCode());
    }

    /**
     * @dataProvider dataModifiedResultWithEtag
     */
    public function testModifiedResultWithEtag(string $etagSeed, string $etagHeaderValue, string $expectedEtag): void
    {
        $middleware = $this->createMiddlewareWithETag($etagSeed);
        $headers = [
            Header::IF_NONE_MATCH => $etagHeaderValue,
        ];
        $response = $middleware->process(
            $this->createServerRequest(Method::GET, $headers),
            $this->createRequestHandler(),
        );

        $this->assertSame(Status::OK, $response->getStatusCode());
        $this->assertSame($response->getHeaderLine('Etag'), $expectedEtag);
    }

    /**
     * @dataProvider dataNotModifiedResultWithEtag
     */
    public function testNotModifiedResultWIthEtag(string $etagSeed, string $etagHeaderValue): void
    {
        $middleware = $this->createMiddlewareWithETag($etagSeed);
        $headers = [
            Header::IF_NONE_MATCH => $etagHeaderValue,
        ];
        $response = $middleware->process(
            $this->createServerRequest(Method::GET, $headers),
            $this->createRequestHandler(),
        );

        $this->assertSame(Status::NOT_MODIFIED, $response->getStatusCode());
        $this->assertEmpty((string)$response->getBody());
    }

    public function testModifiedResultWithWeakEtag(): void
    {
        $etag = 'test-etag';
        $middleware = $this->createMiddlewareWithETag($etag)->withWeakEtag();
        $headers = [
            Header::IF_NONE_MATCH => $etag,
        ];
        $response = $middleware->process(
            $this->createServerRequest(Method::GET, $headers),
            $this->createRequestHandler(),
        );

        $this->assertSame(Status::OK, $response->getStatusCode());
        $this->assertSame($response->getHeaderLine('Etag'), 'W/"IMPoQ2/Us52fJk3jpOZtEACPlVA"');
    }

    /**
     * @dataProvider dataNotModifiedResultWithLastModified
     */
    public function testNotModifiedResultWithLastModified(
        int $lastModified,
        ?string $etagSeed,
        string $ifModifiedSince,
        string $expectedLastModified,
    ): void {
        $middleware = $this->createMiddlewareWithLastModified($lastModified);
        if ($etagSeed !== null) {
            $middleware = $middleware->withEtagSeed(fn() => $etagSeed);
        }

        $headers = [
            Header::IF_MODIFIED_SINCE => $ifModifiedSince,
        ];
        $response = $middleware->process(
            $this->createServerRequest(Method::GET, $headers),
            $this->createRequestHandler(),
        );

        $this->assertSame(Status::NOT_MODIFIED, $response->getStatusCode());
        $this->assertEmpty((string)$response->getBody());
        $this->assertSame($expectedLastModified, $response->getHeaderLine('Last-Modified'));
    }

    public function testModifiedResultWithLastModifiedAndEmptyIfNoneMatchHeader(): void
    {
        $time = time();
        $middleware = $this->createMiddlewareWithLastModified($time - 1);

        $headers = [
            Header::IF_NONE_MATCH => '',
        ];
        $response = $middleware->process(
            $this->createServerRequest(Method::GET, $headers),
            $this->createRequestHandler(),
        );

        $this->assertSame(Status::OK, $response->getStatusCode());
        $this->assertEmpty((string)$response->getBody());
        $this->assertSame($this->formatTime($time - 1), $response->getHeaderLine('Last-Modified'));
    }

    public function testEmptyIfNoneMatchAndIfModifiedSinceHeaders(): void
    {
        $middleware = (new HttpCache())
            ->withEtagSeed(static fn() => 'test-etag')
            ->withLastModified(static fn() => time() + 3600);

        $response = $middleware->process($this->createServerRequest(), $this->createRequestHandler());

        $this->assertSame(Status::OK, $response->getStatusCode());
        $this->assertEmpty((string)$response->getBody());
    }

    public function testImmutability(): void
    {
        $middleware = new HttpCache();

        $this->assertNotSame($middleware, $middleware->withLastModified(static fn() => 3600));
        $this->assertNotSame($middleware, $middleware->withEtagSeed(static fn() => 'test-etag'));
        $this->assertNotSame($middleware, $middleware->withWeakEtag());
        $this->assertNotSame($middleware, $middleware->withParams(['key' => 'value']));
        $this->assertNotSame($middleware, $middleware->withCacheControlHeader('public, max-age=3600'));
    }

    private function createMiddlewareWithLastModified(int $lastModified): HttpCache
    {
        $middleware = new HttpCache();
        return $middleware->withLastModified(static fn() => $lastModified);
    }

    private function createMiddlewareWithETag(string $etag): HttpCache
    {
        $middleware = new HttpCache();
        return $middleware->withEtagSeed(fn() => $etag);
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
