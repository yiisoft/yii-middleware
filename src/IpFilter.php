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
 * `IpFilter` validates the IP received in the request.
 */
final class IpFilter implements MiddlewareInterface
{
    /**
     * @param ValidatorInterface $validator Client IP validator. The properties of the validator
     * can be modified up to the moment of processing.
     * @param ResponseFactoryInterface $responseFactory The response factory instance.
     * @param string|null $clientIpAttribute Attribute name of client IP. If `null`, then `REMOTE_ADDR` value
     * of the server parameters is processed. If the value is not `null`, then the attribute specified
     * must have a value, otherwise the request will be closed with forbidden.
     * @param array $ipRanges The IPv4 or IPv6 ranges that are allowed or forbidden.
     *
     * @psalm-param array<array-key, string> $ipRanges
     */
    public function __construct(
        private ValidatorInterface $validator,
        private ResponseFactoryInterface $responseFactory,
        private ?string $clientIpAttribute = null,
        private array $ipRanges = [],
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->clientIpAttribute !== null) {
            /** @var mixed $clientIp */
            $clientIp = $request->getAttribute($this->clientIpAttribute);
        }

        /** @psalm-var array{REMOTE_ADDR?: mixed} $serverParams */
        $serverParams = $request->getServerParams();
        /** @var mixed $clientIp */
        $clientIp ??= $serverParams['REMOTE_ADDR'] ?? null;

        $result = $this->validator->validate(
            $clientIp,
            [new Ip(allowSubnet: false, allowNegation: false, ranges: $this->ipRanges)]
        );

        if (
            $clientIp === null
            || !$result->isValid()
        ) {
            $response = $this->responseFactory->createResponse(Status::FORBIDDEN);
            $response->getBody()->write(Status::TEXTS[Status::FORBIDDEN]);
            return $response;
        }

        return $handler->handle($request);
    }
}
