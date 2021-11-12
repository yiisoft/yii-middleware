<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Http\Header;
use Yiisoft\Http\Status;

use function strcasecmp;

/**
 * Redirects insecure requests from HTTP to HTTPS, and adds headers necessary to enhance security policy.
 *
 * HTTP Strict-Transport-Security (HSTS) header is added to each response and tells the browser
 * that your site works on HTTPS only.
 *
 * The Content-Security-Policy (CSP) header can force the browser to load page resources only through a secure
 * connection, even if links in the page layout are specified with an unprotected protocol.
 *
 * Note: Prefer forcing HTTPS via web server in case you are not creating installable product such as CMS and not
 * hosting the project on a server where you do not have access to web server configuration.
 */
final class ForceSecureConnection implements MiddlewareInterface
{
    private const DEFAULT_CSP_DIRECTIVES = 'upgrade-insecure-requests; default-src https:';
    private const DEFAULT_HSTS_MAX_AGE = 31_536_000; // 12 months

    private bool $redirect = true;
    private int $statusCode = Status::MOVED_PERMANENTLY;
    private ?int $port = null;

    private bool $addCSP = true;
    private string $cspDirectives = self::DEFAULT_CSP_DIRECTIVES;

    private bool $addHSTS = true;
    private int $hstsMaxAge = self::DEFAULT_HSTS_MAX_AGE;
    private bool $hstsSubDomains = false;

    private ResponseFactoryInterface $responseFactory;

    public function __construct(ResponseFactoryInterface $responseFactory)
    {
        $this->responseFactory = $responseFactory;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->redirect && strcasecmp($request->getUri()->getScheme(), 'http') === 0) {
            $url = (string) $request->getUri()->withScheme('https')->withPort($this->port);

            return $this->addHSTS(
                $this->responseFactory
                    ->createResponse($this->statusCode)
                    ->withHeader(Header::LOCATION, $url)
            );
        }

        return $this->addHSTS($this->addCSP($handler->handle($request)));
    }

    /**
     * Redirects from HTTP to HTTPS
     *
     * @param int $statusCode
     * @param int|null $port
     *
     * @return self
     */
    public function withRedirection(int $statusCode = Status::MOVED_PERMANENTLY, int $port = null): self
    {
        $new = clone $this;
        $new->redirect = true;
        $new->port = $port;
        $new->statusCode = $statusCode;
        return $new;
    }

    public function withoutRedirection(): self
    {
        $new = clone $this;
        $new->redirect = false;
        return $new;
    }

    /**
     * Add Content-Security-Policy header to response.
     *
     * @see Header::CONTENT_SECURITY_POLICY
     *
     * @param string $directives
     *
     * @return self
     */
    public function withCSP(string $directives = self::DEFAULT_CSP_DIRECTIVES): self
    {
        $new = clone $this;
        $new->addCSP = true;
        $new->cspDirectives = $directives;
        return $new;
    }

    public function withoutCSP(): self
    {
        $new = clone $this;
        $new->addCSP = false;
        return $new;
    }

    /**
     * Add Strict-Transport-Security header to each response.
     *
     * @see Header::STRICT_TRANSPORT_SECURITY
     *
     * @param int $maxAge
     * @param bool $subDomains
     *
     * @return self
     */
    public function withHSTS(int $maxAge = self::DEFAULT_HSTS_MAX_AGE, bool $subDomains = false): self
    {
        $new = clone $this;
        $new->addHSTS = true;
        $new->hstsMaxAge = $maxAge;
        $new->hstsSubDomains = $subDomains;
        return $new;
    }

    public function withoutHSTS(): self
    {
        $new = clone $this;
        $new->addHSTS = false;
        return $new;
    }

    private function addCSP(ResponseInterface $response): ResponseInterface
    {
        return $this->addCSP
            ? $response->withHeader(Header::CONTENT_SECURITY_POLICY, $this->cspDirectives)
            : $response;
    }

    private function addHSTS(ResponseInterface $response): ResponseInterface
    {
        $subDomains = $this->hstsSubDomains ? '; includeSubDomains' : '';
        return $this->addHSTS
            ? $response->withHeader(Header::STRICT_TRANSPORT_SECURITY, "max-age={$this->hstsMaxAge}{$subDomains}")
            : $response;
    }
}
