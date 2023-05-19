<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Middleware\Storage;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Cookies\Cookie;

final class CookieLocaleStorage implements LocaleStorageWithHttpFlowInterface
{
    private ?ServerRequestInterface $request = null;
    private ?ResponseInterface $response = null;

    public function __construct(
        private Cookie $cookie,
    ) {
    }

    public function getName(): string
    {
        return 'cookie';
    }

    public function set(string $value): void
    {
        $this->cookie = $this->cookie->withValue($value);

        if ($this->response !== null) {
            $this->response = $this->cookie->addToResponse($this->response);
        }
    }

    public function get(): ?string
    {
        return $this?->request?->getCookieParams()[$this->cookie->getName()] ?? null;
    }

    public function withRequest(ServerRequestInterface $request): self
    {
        $new = clone $this;
        $new->request = $request;
        return $new;
    }

    public function withResponse(ResponseInterface $response): self
    {
        $new = clone $this;
        $new->response = $response;
        return $new;
    }

    public function getResponse(): ?ResponseInterface
    {
        return $this->response;
    }
}
