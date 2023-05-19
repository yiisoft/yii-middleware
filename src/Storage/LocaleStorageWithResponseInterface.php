<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Middleware\Storage;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

interface LocaleStorageWithResponseInterface extends LocaleStorageInterface
{
    public function withResponse(ResponseInterface $response): self;

    public function withRequest(ServerRequestInterface $request): self;

    public function getResponse(): ResponseInterface;
}
