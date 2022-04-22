<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Http\Status;
use Yiisoft\Validator\Rule\Ip;
use Yiisoft\Validator\ValidatorInterface;

/**
 * IpFilter validates the IP received in the request.
 */
final class IpFilter implements MiddlewareInterface
{
    /**
     * @param ValidatorInterface $validator The validator to use.
     * @param ResponseFactoryInterface $responseFactory The response factory instance.
     * @param string|null $clientIpAttribute Attribute name of client IP. If `null`, then `REMOTE_ADDR` value
     * of the server parameters is processed. If the value is not `null`, then the attribute specified
     * must have a value, otherwise the request will closed with forbidden.
     */
    public function __construct(
        private ValidatorInterface $validator,
        private ResponseFactoryInterface $responseFactory,
        private ?string $clientIpAttribute = null,
        private array $ipRanges = []
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->clientIpAttribute !== null) {
            $clientIp = $request->getAttribute($this->clientIpAttribute);
        }

        $clientIp ??= $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $validationResult = $this->validator->validate(
            ['ip' => $clientIp],
            [
                'ip' => [
                    new Ip(
                        allowSubnet: false,
                        allowNegation: false,
                        ranges: $this->ipRanges
                    ),
                ],
            ]
        );

        if ($clientIp === null || !$validationResult->isValid()) {
            $response = $this->responseFactory->createResponse(Status::FORBIDDEN);
            $response->getBody()->write(Status::TEXTS[Status::FORBIDDEN]);
            return $response;
        }

        return $handler->handle($request);
    }
}
