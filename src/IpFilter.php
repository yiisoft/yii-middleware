<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Http\Status;
use Yiisoft\Validator\Rule\Ip\Ip;
use Yiisoft\Validator\ValidatorInterface;

/**
 * IpFilter validates the IP received in the request.
 */
final class IpFilter implements MiddlewareInterface
{
    private ValidatorInterface $validator;
    private ResponseFactoryInterface $responseFactory;
    private ?string $clientIpAttribute;

    /**
     * @param Ip $validator Client IP validator. The properties of the validator
     * can be modified up to the moment of processing.
     * @param ResponseFactoryInterface $responseFactory The response factory instance.
     * @param string|null $clientIpAttribute Attribute name of client IP. If `null`, then `REMOTE_ADDR` value
     * of the server parameters is processed. If the value is not `null`, then the attribute specified
     * must have a value, otherwise the request will closed with forbidden.
     */
    public function __construct(
        ValidatorInterface $validator,
        ResponseFactoryInterface $responseFactory,
        string $clientIpAttribute = null
    ) {
        $this->validator = $validator;
        $this->responseFactory = $responseFactory;
        $this->clientIpAttribute = $clientIpAttribute;
    }

    /**
     * Returns a new instance with the specified client IP validator.
     *
     * @param Ip $validator Client IP validator. The properties of the validator
     * can be modified up to the moment of processing.
     *
     * @return self
     */
    public function withValidator(ValidatorInterface $validator): self
    {
        $new = clone $this;
        $new->validator = $validator;
        return $new;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->clientIpAttribute !== null) {
            $clientIp = $request->getAttribute($this->clientIpAttribute);
        }

        $clientIp ??= $request->getServerParams()['REMOTE_ADDR'] ?? null;

        if (
            $clientIp === null
            || !$this->validator->validate($clientIp, [new Ip(allowNegation: false, allowSubnet: false)])->isValid()
        ) {
            $response = $this->responseFactory->createResponse(Status::FORBIDDEN);
            $response->getBody()->write(Status::TEXTS[Status::FORBIDDEN]);
            return $response;
        }

        return $handler->handle($request);
    }
}
