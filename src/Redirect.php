<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Yiisoft\Http\Status;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * This middleware generates and adds a `Location` header to the response.
 */
final class Redirect implements MiddlewareInterface
{
    private ?string $uri = null;
    private ?string $route = null;
    private array $parameters = [];
    private int $statusCode = Status::MOVED_PERMANENTLY;
    private ResponseFactoryInterface $responseFactory;
    private UrlGeneratorInterface $urlGenerator;

    public function __construct(ResponseFactoryInterface $responseFactory, UrlGeneratorInterface $urlGenerator)
    {
        $this->responseFactory = $responseFactory;
        $this->urlGenerator = $urlGenerator;
    }

    /**
     * Returns a new instance with the specified URL for redirection.
     *
     * @param string $url URL for redirection.
     *
     * @return self
     */
    public function toUrl(string $url): self
    {
        $new = clone $this;
        $new->uri = $url;
        return $new;
    }

    /**
     * Returns a new instance with the specified route data for redirection.
     *
     * If a redirect URL has been set {@see toUrl()}, the route data will be ignored, since the URL is a priority.
     *
     * @param string $name The route name for redirection.
     * @param array $parameters The route parameters for redirection.
     *
     * @return self
     */
    public function toRoute(string $name, array $parameters = []): self
    {
        $new = clone $this;
        $new->route = $name;
        $new->parameters = $parameters;
        return $new;
    }

    /**
     * Returns a new instance with the specified status code of the response for redirection.
     *
     * @param int $statusCode The status code of the response for redirection.
     *
     * @return self
     */
    public function withStatus(int $statusCode): self
    {
        $new = clone $this;
        $new->statusCode = $statusCode;
        return $new;
    }

    /**
     * Returns a new instance with the response status code of permanent redirection.
     *
     * @see Status::MOVED_PERMANENTLY
     *
     * @return self
     */
    public function permanent(): self
    {
        $new = clone $this;
        $new->statusCode = Status::MOVED_PERMANENTLY;
        return $new;
    }

    /**
     * Returns a new instance with the response status code of temporary redirection.
     *
     * @see Status::FOUND
     *
     * @return self
     */
    public function temporary(): self
    {
        $new = clone $this;
        $new->statusCode = Status::FOUND;
        return $new;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->route === null && $this->uri === null) {
            throw new RuntimeException('Either `toUrl()` or `toRoute()` method should be used.');
        }

        $uri = $this->uri ?? $this->urlGenerator->generate($this->route, $this->parameters);

        return $this->responseFactory
            ->createResponse($this->statusCode)
            ->withAddedHeader('Location', $uri);
    }
}
