<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Http\Header;
use Yiisoft\Http\Method;
use Yiisoft\Http\Status;

use function base64_encode;
use function in_array;
use function rtrim;
use function preg_split;
use function sha1;
use function str_replace;
use function strtotime;

/**
 * HttpCache implements client-side caching by utilizing the `Last-Modified` and `ETag` HTTP headers.
 */
final class HttpCache implements MiddlewareInterface
{
    /**
     * @var callable|null
     */
    private $lastModified = null;

    /**
     * @var callable|null
     */
    private $etagSeed = null;

    private bool $weakEtag = false;
    private mixed $params = null;
    private ?string $cacheControlHeader = 'public, max-age=3600';

    /**
     * Returns a new instance with the specified callable that generates the last modified.
     *
     * @param callable A PHP callback that returns the UNIX timestamp of the last modification time.
     *
     * The callback's signature should be:
     *
     * ```php
     * function (ServerRequestInterface $request, mixed $params): int;
     * ```
     *
     * Where `$request` is the {@see ServerRequestInterface} object that this filter is currently handling;
     * `$params` takes the value of {@see withParams()}. The callback should return a UNIX timestamp.
     *
     * @see http://tools.ietf.org/html/rfc7232#section-2.2
     *
     * @return self
     */
    public function withLastModified(callable $lastModified): self
    {
        $new = clone $this;
        $new->lastModified = $lastModified;
        return $new;
    }

    /**
     * Returns a new instance with the specified callable that generates the ETag seed string.
     *
     * @param callable A PHP callback that generates the ETag seed string.
     *
     * The callback's signature should be:
     *
     * ```php
     * function (ServerRequestInterface $request, mixed $params): string;
     * ```
     *
     * Where `$request` is the {@see ServerRequestInterface} object that this middleware is currently handling;
     * `$params` takes the value of {@see withParams()}. The callback should return a string serving
     * as the seed for generating an ETag.
     *
     * @return self
     */
    public function withEtagSeed(callable $etagSeed): self
    {
        $new = clone $this;
        $new->etagSeed = $etagSeed;
        return $new;
    }

    /**
     * Returns a new instance with weak ETags generation enabled. Disabled by default.
     *
     * Weak ETags should be used if the content should be considered semantically equivalent, but not byte-equal.
     *
     * @see http://tools.ietf.org/html/rfc7232#section-2.3
     *
     * @return self
     */
    public function withWeakTag(): self
    {
        $new = clone $this;
        $new->weakEtag = true;
        return $new;
    }

    /**
     * Returns a new instance with the specified additional parameters for ETag seed string generation.
     *
     * @param mixed Additional parameters that should be passed to the {@see withEtagSeed()} callbacks.
     *
     * @return self
     */
    public function withParams(mixed $params): self
    {
        $new = clone $this;
        $new->params = $params;
        return $new;
    }

    /**
     * Returns a new instance with the specified value of the `Cache-Control` HTTP header.
     *
     * @param string|null The value of the `Cache-Control` HTTP header. If null, the header will not be sent.
     *
     * @see http://tools.ietf.org/html/rfc2616#section-14.9
     *
     * @return self
     */
    public function withCacheControlHeader(?string $header): self
    {
        $new = clone $this;
        $new->cacheControlHeader = $header;
        return $new;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (
            ($this->lastModified === null && $this->etagSeed === null) ||
            !in_array($request->getMethod(), [Method::GET, Method::HEAD], true)
        ) {
            return $handler->handle($request);
        }

        $lastModified = $this->lastModified === null ? null : ($this->lastModified)($request, $this->params);
        $etag = null;

        if ($this->etagSeed !== null) {
            $seed = ($this->etagSeed)($request, $this->params);

            if ($seed !== null) {
                $etag = $this->generateEtag($seed);
            }
        }

        $cacheIsValid = $this->validateCache($request, $lastModified, $etag);
        $response = $handler->handle($request);

        if ($cacheIsValid) {
            $response = $response->withStatus(Status::NOT_MODIFIED);
        }

        if ($this->cacheControlHeader !== null) {
            $response = $response->withHeader(Header::CACHE_CONTROL, $this->cacheControlHeader);
        }
        if ($etag !== null) {
            $response = $response->withHeader(Header::ETAG, $etag);
        }

        // See: https://tools.ietf.org/html/rfc7232#section-4.1
        if ($lastModified !== null && (!$cacheIsValid || $etag === null)) {
            $response = $response->withHeader(
                Header::LAST_MODIFIED,
                gmdate('D, d M Y H:i:s', $lastModified) . ' GMT',
            );
        }

        return $response;
    }

    /**
     * Validates if the HTTP cache contains valid content. If both Last-Modified and ETag are null, returns false.
     *
     * @param ServerRequestInterface $request The server request instance.
     * @param int|null $lastModified The calculated Last-Modified value in terms of a UNIX timestamp.
     * If null, the Last-Modified header will not be validated.
     * @param string|null $etag The calculated ETag value. If null, the ETag header will not be validated.
     *
     * @return bool Whether the HTTP cache is still valid.
     */
    private function validateCache(ServerRequestInterface $request, ?int $lastModified, ?string $etag): bool
    {
        if ($request->hasHeader(Header::IF_NONE_MATCH)) {
            $header = preg_split(
                '/[\s,]+/',
                str_replace('-gzip', '', $request->getHeaderLine(Header::IF_NONE_MATCH)),
                -1,
                PREG_SPLIT_NO_EMPTY,
            );

            // HTTP_IF_NONE_MATCH takes precedence over HTTP_IF_MODIFIED_SINCE
            // http://tools.ietf.org/html/rfc7232#section-3.3
            return $etag !== null && !empty($header) && in_array($etag, $header, true);
        }

        if ($request->hasHeader(Header::IF_MODIFIED_SINCE)) {
            $header = $request->getHeaderLine(Header::IF_MODIFIED_SINCE);
            return $lastModified !== null && @strtotime($header) >= $lastModified;
        }

        return false;
    }

    /**
     * Generates an ETag from the given seed string.
     *
     * @param string $seed Seed for the ETag
     *
     * @return string the generated ETag
     */
    private function generateEtag(string $seed): string
    {
        $etag = '"' . rtrim(base64_encode(sha1($seed, true)), '=') . '"';
        return $this->weakEtag ? 'W/' . $etag : $etag;
    }
}
