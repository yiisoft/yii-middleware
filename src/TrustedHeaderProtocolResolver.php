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
 * Trusted header protocol resolver sets a server request protocol based on a special header you trust
 * such as `X-Forwarded-Proto`.
 *
 * You can use it if your server is behind a trusted load balancer or a proxy that's always setting the special header
 * itself discarding any header values provided by user.
 *
 * If you want to trust headers for a certain IP, use {@see TrustedHostsNetworkResolver}.
 */
final class TrustedHeaderProtocolResolver implements MiddlewareInterface
{
    /**
     * Default mapping of protocol to header values.
     */
    private const DEFAULT_PROTOCOLS_TO_HEADER_VALUES = [
        'http' => ['http'],
        'https' => ['https', 'on'],
    ];

    /**
     * Lowercase trusted protocol headers and their corresponding mapping of protocols to header values.
     *
     * ```php
     * [
     *     'x-forwarded-proto' => [
     *         'http' => ['http'],
     *         'https' => ['https'],
     *     ],
     * ]
     * ```
     *
     * Instead of the mapping, it could be a callable.
     * See {@see withAddedProtocolHeader()}.
     *
     * @psalm-var array<string, non-empty-array<non-empty-string, non-empty-array<array-key, string>>|callable>
     */
    private array $protocolHeaders = [];

    /**
     * Returns a new instance with the specified protocol header added and protocols mapped to its values.
     * It's used to check whether the connection is HTTP / HTTPS or any other protocol.
     *
     * The match of header names and values is case-insensitive.
     * Avoid adding insecure/untrusted headers that a user might set.
     *
     * Accepted values:
     *
     * - `null` (default): Default mapping, see {@see DEFAULT_PROTOCOLS_TO_HEADER_VALUES}
     * - callable: custom function for getting the protocol
     * ```php
     * ->withProtocolHeader(
     *     'x-forwarded-proto',
     *     function (array $values, string $header, ServerRequestInterface $request): ?string {
     *         return $values[0] === 'https' ? 'https' : 'http';
     *         return null; // If you can not decide on the protocol.
     *     },
     * );
     * ```
     * - array: The array keys are protocols, and the array values are lists of header values that the header
     * must have for the corresponding protocol.
     *
     * ```php
     * ->withProtocolHeader('x-forwarded-proto', [
     *     'http' => ['http'],
     *     'https' => ['https'],
     * ]);
     * ```
     *
     * @param string $header The trusted protocol header name.
     * @param array|callable|null $values The protocol mapping to header values.
     *
     * @psalm-param array<array-key, string|string[]>|callable|null $values
     */
    public function withAddedProtocolHeader(string $header, array|callable|null $values = null): self
    {
        $new = clone $this;
        $header = strtolower($header);

        if ($values === null) {
            $new->protocolHeaders[$header] = self::DEFAULT_PROTOCOLS_TO_HEADER_VALUES;
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
     * @param string[] $headers The protocol header names. If you specify `null` all protocol headers will be removed.
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
     * @throws RuntimeException If URI scheme protocol is wrong.
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
