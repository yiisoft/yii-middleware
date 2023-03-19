<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

use function array_map;
use function in_array;
use function is_callable;
use function is_string;
use function strtolower;

/**
 * Basic network resolver updates an instance of server request with protocol from special headers.
 *
 * It can be used in if your server is behind a trusted load balancer or a proxy that is setting a special header.
 */
final class BasicNetworkResolver implements MiddlewareInterface
{
    private const DEFAULT_PROTOCOL_AND_ACCEPTABLE_VALUES = [
        'http' => ['http'],
        'https' => ['https', 'on'],
    ];

    /**
     * @psalm-var array<string, non-empty-array<non-empty-string, non-empty-array<array-key, string>>|callable>
     */
    private array $protocolHeaders = [];

    /**
     * Returns a new instance with added the specified protocol header to check
     * whether the connection is made via HTTP or HTTPS (or any protocol).
     *
     * The match of header names and values is case-insensitive.
     * It's not advisable to put insecure/untrusted headers here.
     *
     * Accepted types of values:
     * - NULL (default): {@see DEFAULT_PROTOCOL_AND_ACCEPTABLE_VALUES}
     * - callable: custom function for getting the protocol
     * ```php
     * ->withProtocolHeader(
     *     'x-forwarded-proto',
     *     function (array $values, string $header, ServerRequestInterface $request): ?string {
     *         return $values[0] === 'https' ? 'https' : 'http';
     *         return null;     // If it doesn't make sense.
     *     },
     * );
     * ```
     * - array: The array keys are protocol string and the array value is a list of header values that
     * indicate the protocol.
     *
     * ```php
     * ->withProtocolHeader('x-forwarded-proto', [
     *     'http' => ['http'],
     *     'https' => ['https'],
     * ]);
     * ```
     *
     * @param string $header The protocol header name.
     * @param array|callable|null $values The protocol header values.
     *
     * @psalm-param array<array-key, string|string[]>|callable|null $values
     */
    public function withAddedProtocolHeader(string $header, array|callable|null $values = null): self
    {
        $new = clone $this;
        $header = strtolower($header);

        if ($values === null) {
            $new->protocolHeaders[$header] = self::DEFAULT_PROTOCOL_AND_ACCEPTABLE_VALUES;
            return $new;
        }

        if (is_callable($values)) {
            $new->protocolHeaders[$header] = $values;
            return $new;
        }

        if (empty($values)) {
            throw new RuntimeException('Protocol header values cannot be an empty array.');
        }

        $protocolHeader = [];
        foreach ($values as $protocol => $acceptedValues) {
            if (!is_string($protocol)) {
                throw new RuntimeException('The protocol must be type of string.');
            }

            if ($protocol === '') {
                throw new RuntimeException('The protocol cannot be an empty string.');
            }

            $acceptedValues = (array) $acceptedValues;
            if (empty($acceptedValues)) {
                throw new RuntimeException('Protocol accepted values cannot be an empty array.');
            }

            $protocolHeader[$protocol] = array_map('\strtolower', $acceptedValues);
        }

        $new->protocolHeaders[$header] = $protocolHeader;

        return $new;
    }

    /**
     * Returns a new instance without the specified protocol header.
     *
     * @param string $header The protocol header name.
     *
     * @see withAddedProtocolHeader()
     */
    public function withoutProtocolHeader(string $header): self
    {
        $new = clone $this;
        unset($new->protocolHeaders[strtolower($header)]);
        return $new;
    }

    /**
     * Returns a new instance without the specified protocol headers.
     *
     * @param string[] $headers The protocol header names. If `null` is specified all protocol headers will be removed.
     *
     * @see withoutProtocolHeader()
     */
    public function withoutProtocolHeaders(?array $headers = null): self
    {
        $new = clone $this;

        if ($headers === null) {
            $new->protocolHeaders = [];
            return $new;
        }

        foreach ($headers as $header) {
            $new = $new->withoutProtocolHeader($header);
        }

        return $new;
    }

    /**
     * {@inheritDoc}
     *
     * @throws RuntimeException If wrong URI scheme protocol.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $newScheme = null;

        foreach ($this->protocolHeaders as $header => $data) {
            if (!$request->hasHeader($header)) {
                continue;
            }

            $headerValues = $request->getHeader($header);

            if (is_callable($data)) {
                /** @var mixed $newScheme */
                $newScheme = $data($headerValues, $header, $request);

                if ($newScheme === null) {
                    continue;
                }

                if (!is_string($newScheme)) {
                    throw new RuntimeException('The scheme is neither string nor null.');
                }

                if ($newScheme === '') {
                    throw new RuntimeException('The scheme cannot be an empty string.');
                }

                break;
            }

            $headerValue = strtolower($headerValues[0]);

            foreach ($data as $protocol => $acceptedValues) {
                if (!in_array($headerValue, $acceptedValues, true)) {
                    continue;
                }

                $newScheme = $protocol;
                break 2;
            }
        }

        $uri = $request->getUri();

        if ($newScheme !== null && $newScheme !== $uri->getScheme()) {
            $request = $request->withUri($uri->withScheme($newScheme));
        }

        return $handler->handle($request);
    }
}
