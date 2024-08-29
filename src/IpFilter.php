<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Http\Status;
use Yiisoft\NetworkUtilities\IpHelper;
use Yiisoft\NetworkUtilities\IpRanges;
use Yiisoft\Validator\ValidatorInterface;

/**
 * `IpFilter` allows access from specified IP ranges only and responds with 403 for all other IPs.
 */
final class IpFilter implements MiddlewareInterface
{
    /**
     * @param ValidatorInterface $validator Client IP validator. The properties of the validator
     * can be modified up to the moment of processing.
     * @param ResponseFactoryInterface $responseFactory The response factory instance.
     * @param string|null $clientIpAttribute Name of the request attribute holding client IP. If there is no such
     * attribute, or it has no value, then the middleware will respond with 403 forbidden.
     * If the name of the request attribute is `null`, then `REMOTE_ADDR` server parameter is used to determine client IP.
     * @param array $ipRanges Allowed IPv4 or IPv6 ranges.
     *
     * @psalm-param array<array-key, string> $ipRanges
     */
    public function __construct(
        /**
         * @deprecated Will be removed in version 2.0. {@see IpRanges} from `network-utilities` package is used instead.
         */
        private ValidatorInterface $validator,
        private ResponseFactoryInterface $responseFactory,
        private ?string $clientIpAttribute = null,
        private array $ipRanges = [],
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->clientIpAttribute !== null) {
            $clientIp = $request->getAttribute($this->clientIpAttribute);
        }

        /** @psalm-var array{REMOTE_ADDR?: mixed} $serverParams */
        $serverParams = $request->getServerParams();
        $clientIp ??= $serverParams['REMOTE_ADDR'] ?? null;
        if ($clientIp === null) {
            return $this->createForbiddenResponse();
        }

        if (!is_string($clientIp) || preg_match(self::getIpParsePattern(), $clientIp, $matches) === 0) {
            return $this->createForbiddenResponse();
        }

        $ipCidr = $matches['ipCidr'];
        if (!(new IpRanges($this->ipRanges))->isAllowed($ipCidr)) {
            return $this->createForbiddenResponse();
        }

        return $handler->handle($request);
    }

    private function createForbiddenResponse(): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(Status::FORBIDDEN);
        $response->getBody()->write(Status::TEXTS[Status::FORBIDDEN]);

        return $response;
    }

    /**
     * Used to get the Regexp pattern for initial IP address parsing.
     *
     * @return string Regular expression pattern.
     * @psalm-return non-empty-string
     */
    private static function getIpParsePattern(): string
    {
        return '/^(?<ipCidr>(?<ip>(?:' . IpHelper::IPV4_PATTERN . ')|(?:' . IpHelper::IPV6_PATTERN . '))(?:\/(?<cidr>-?\d+))?)$/';
    }
}
