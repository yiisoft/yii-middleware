<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function uniqid;

/**
 * Tags request with a random value that could be later used for identifying it.
 */
final class TagRequest implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request->withAttribute('requestTag', $this->getRequestTag()));
    }

    private function getRequestTag(): string
    {
        return uniqid('', true);
    }
}
