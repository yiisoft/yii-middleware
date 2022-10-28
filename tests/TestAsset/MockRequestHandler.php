<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Middleware\Tests\TestAsset;

use HttpSoft\Message\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;
use Yiisoft\Http\Status;

final class MockRequestHandler implements RequestHandlerInterface
{
    public ?ServerRequestInterface $processedRequest = null;
    private ?Throwable $handleException = null;

    public function __construct(private int $responseStatusCode = Status::OK)
    {
    }

    public function setHandleException(?Throwable $throwable): self
    {
        $this->handleException = $throwable;
        return $this;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->handleException !== null) {
            throw $this->handleException;
        }

        $this->processedRequest = $request;
        return new Response($this->responseStatusCode);
    }
}
