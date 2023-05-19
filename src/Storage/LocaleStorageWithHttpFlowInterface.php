<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Middleware\Storage;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

interface LocaleStorageWithHttpFlowInterface extends LocaleStorageInterface
{
    public function withRequest(ServerRequestInterface $request): self;

    public function withResponse(ResponseInterface $response): self;

    public function getResponse(): ?ResponseInterface;
}
