<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Middleware;

use Closure;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Yiisoft\Http\HeaderValueHelper;
use Yiisoft\NetworkUtilities\IpHelper;
use Yiisoft\Validator\Result;
use Yiisoft\Validator\Rule\Ip;
use Yiisoft\Validator\ValidatorInterface;

use function array_diff;
use function array_reverse;
use function array_shift;
use function array_unshift;
use function count;
use function explode;
use function filter_var;
use function in_array;
use function is_array;
use function is_callable;
use function is_string;
use function preg_match;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function trim;

/**
 * Trusted hosts network resolver can set IP, protocol, host, URL, and port based on trusted headers such as
 * `Forward` or `X-Forwarded-Host` coming from trusted hosts you define. Usually these are load balancers.
 *
 * Make sure that the trusted host always overwrites or removes user-defined headers
 * to avoid security issues.
 *
 * ```php
 * $trustedHostsNetworkResolver->withAddedTrustedHosts(
 *     // List of secure hosts including "$_SERVER['REMOTE_ADDR']".
 *     hosts: ['1.1.1.1', '2.2.2.1/3', '2001::/32', 'localhost'].
 *     // IP list headers.
 *     // Headers containing multiple sub-elements (e.g. RFC 7239) must also be listed for other relevant types
 *     // (such as host headers), otherwise they will only be used as an IP list.
 *     ipHeaders: ['x-forwarded-for', [TrustedHostsNetworkResolver::IP_HEADER_TYPE_RFC7239, 'forwarded']]
 *     // Protocol headers with accepted protocols and corresponding header values. Matching is case-insensitive.
 *     protocolHeaders: ['front-end-https' => ['https' => 'on']],
 *     // List of headers containing HTTP host.
 *     hostHeaders: ['forwarded', 'x-forwarded-for']
 *     // List of headers containing HTTP URL.
 *     urlHeaders: ['x-rewrite-url'],
 *     // List of headers containing port number.
 *     portHeaders: ['x-rewrite-port'],
 *     // List of trusted headers. For untrusted hosts, middleware removes these from the request.
 *     trustedHeaders: ['x-forwarded-for', 'forwarded', ...],
 * );
 * ```
 *
 * @psalm-type HostData = array{ip?:string, host?: string, by?: string, port?: string|int, protocol?: string, httpHost?: string}
 * @psalm-type ProtocolHeadersData = array<string, array<non-empty-string, array<array-key, string>>|callable>
 * @psalm-type TrustedHostData = array{
 *     'hosts': array<array-key, string>,
 *     'ipHeaders': array<array-key, string>,
 *     'protocolHeaders': ProtocolHeadersData,
 *     'hostHeaders': array<array-key, string>,
 *     'urlHeaders': array<array-key, string>,
 *     'portHeaders': array<array-key, string>,
 *     'trustedHeaders': array<array-key, string>
 * }
 */
class TrustedHostsNetworkResolver implements MiddlewareInterface
{
    /**
     * Name of the request attribute holding IP address obtained from a trusted header.
     */
    public const REQUEST_CLIENT_IP = 'requestClientIp';

    /**
     * Indicates that middleware should obtain IP from `Forwarded` header.
     *
     * @link https://www.rfc-editor.org/rfc/rfc7239.html
     */
    public const IP_HEADER_TYPE_RFC7239 = 'rfc7239';

    /**
     * List of headers to trust for any trusted host.
     */
    public const DEFAULT_TRUSTED_HEADERS = [
        // common:
        'x-forwarded-for',
        'x-forwarded-host',
        'x-forwarded-proto',
        'x-forwarded-port',

        // RFC:
        'forward',

        // Microsoft:
        'front-end-https',
        'x-rewrite-url',
    ];

    private const DATA_KEY_HOSTS = 'hosts';
    private const DATA_KEY_IP_HEADERS = 'ipHeaders';
    private const DATA_KEY_HOST_HEADERS = 'hostHeaders';
    private const DATA_KEY_URL_HEADERS = 'urlHeaders';
    private const DATA_KEY_PROTOCOL_HEADERS = 'protocolHeaders';
    private const DATA_KEY_TRUSTED_HEADERS = 'trustedHeaders';
    private const DATA_KEY_PORT_HEADERS = 'portHeaders';

    /**
     * @var array<TrustedHostData>
     */
    private array $trustedHosts = [];
    private ?string $attributeIps = null;

    public function __construct(private ValidatorInterface $validator)
    {
    }

    /**
     * Returns a new instance with the added trusted hosts and related headers.
     *
     * The header lists are evaluated in the order they were specified.
     *
     * Make sure that the trusted host always overwrites or removes user-defined headers
     * to avoid security issues.
     *
     * @param string[] $hosts List of trusted host IP addresses. The {@see isValidHost()} method could be overwritten in
     * a subclass to allow using domain names with reverse DNS resolving, for example `yiiframework.com`,
     * `*.yiiframework.com`. You can specify IPv4, IPv6, domains, and aliases. See {@see Ip}.
     * @param array $ipHeaders List of headers containing IP. For advanced handling of headers see
     * {@see TrustedHostsNetworkResolver::IP_HEADER_TYPE_RFC7239}.
     * @param array $protocolHeaders List of headers containing protocol. e.g.
     * `['x-forwarded-for' => ['http' => 'http', 'https' => ['on', 'https']]]`.
     * @param string[] $hostHeaders List of headers containing HTTP host.
     * @param string[] $urlHeaders List of headers containing HTTP URL.
     * @param string[] $portHeaders List of headers containing port number.
     * @param string[]|null $trustedHeaders List of trusted headers. For untrusted hosts, middleware removes these from
     * the request.
     */
    public function withAddedTrustedHosts(
        array $hosts,
        // Defining default headers isn't secure!
        array $ipHeaders = [],
        array $protocolHeaders = [],
        array $hostHeaders = [],
        array $urlHeaders = [],
        array $portHeaders = [],
        ?array $trustedHeaders = null,
    ): self {
        if ($hosts === []) {
            throw new InvalidArgumentException('Empty hosts are not allowed.');
        }

        foreach ($ipHeaders as $ipHeader) {
            if (is_string($ipHeader)) {
                continue;
            }

            if (!is_array($ipHeader)) {
                throw new InvalidArgumentException('IP header must have either string or array type.');
            }

            if (count($ipHeader) !== 2) {
                throw new InvalidArgumentException('IP header array must have exactly 2 elements.');
            }

            [$type, $header] = $ipHeader;

            if (!is_string($type)) {
                throw new InvalidArgumentException('IP header type must be a string.');
            }

            if (!is_string($header)) {
                throw new InvalidArgumentException('IP header value must be a string.');
            }

            if ($type === self::IP_HEADER_TYPE_RFC7239) {
                continue;
            }

            throw new InvalidArgumentException("Not supported IP header type: \"$type\".");
        }

        $trustedHeaders ??= self::DEFAULT_TRUSTED_HEADERS;
        /** @psalm-var ProtocolHeadersData $protocolHeaders */
        $protocolHeaders = $this->prepareProtocolHeaders($protocolHeaders);

        $this->requireListOfNonEmptyStrings($hosts, self::DATA_KEY_HOSTS);
        $this->requireListOfNonEmptyStrings($trustedHeaders, self::DATA_KEY_TRUSTED_HEADERS);
        $this->requireListOfNonEmptyStrings($hostHeaders, self::DATA_KEY_HOST_HEADERS);
        $this->requireListOfNonEmptyStrings($urlHeaders, self::DATA_KEY_URL_HEADERS);
        $this->requireListOfNonEmptyStrings($portHeaders, self::DATA_KEY_PORT_HEADERS);

        foreach ($hosts as $host) {
            /**
             * Wildcard is allowed in host. It's replaced by placeholder temporarily just for validation, because it's
             * not supported by {@see filter_var}.
             */
            $host = str_replace('*', 'wildcard', $host);

            if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                throw new InvalidArgumentException("\"$host\" host must be either a domain or an IP address.");
            }
        }

        $new = clone $this;
        /** @psalm-var array<array-key, string> $ipHeaders */
        $new->trustedHosts[] = [
            self::DATA_KEY_HOSTS => $hosts,
            self::DATA_KEY_IP_HEADERS => $ipHeaders,
            self::DATA_KEY_PROTOCOL_HEADERS => $protocolHeaders,
            self::DATA_KEY_TRUSTED_HEADERS => $trustedHeaders,
            self::DATA_KEY_HOST_HEADERS => $hostHeaders,
            self::DATA_KEY_URL_HEADERS => $urlHeaders,
            self::DATA_KEY_PORT_HEADERS => $portHeaders,
        ];

        return $new;
    }

    /**
     * Returns a new instance without the trusted hosts and related headers.
     */
    public function withoutTrustedHosts(): self
    {
        $new = clone $this;
        $new->trustedHosts = [];
        return $new;
    }

    /**
     * Returns a new instance with the specified request's attribute name to which middleware writes trusted path data.
     *
     * @param string|null $attribute The request attribute name.
     *
     * @see getElementsByRfc7239()
     */
    public function withAttributeIps(?string $attribute): self
    {
        if ($attribute === '') {
            throw new RuntimeException('Attribute should not be empty string.');
        }

        $new = clone $this;
        $new->attributeIps = $attribute;
        return $new;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var string|null $actualHost */
        $actualHost = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        if ($actualHost === null) {
            // Validation isn't possible.
            return $this->handleNotTrusted($request, $handler);
        }

        $trustedHostData = null;
        $trustedHeaders = [];

        foreach ($this->trustedHosts as $data) {
            // Collect all trusted headers.
            $trustedHeaders[] = $data[self::DATA_KEY_TRUSTED_HEADERS];

            if ($trustedHostData === null && $this->isValidHost($actualHost, $data[self::DATA_KEY_HOSTS])) {
                $trustedHostData = $data;
            }
        }

        if ($trustedHostData === null) {
            // No trusted host at all.
            return $this->handleNotTrusted($request, $handler);
        }

        $trustedHeaders = array_merge(...$trustedHeaders);
        /** @psalm-var array<string, array<array-key,string>> $requestHeaders */
        $requestHeaders = $request->getHeaders();
        $untrustedHeaders = array_diff(array_keys($requestHeaders), $trustedHeaders);
        $request = $this->removeHeaders($request, $untrustedHeaders);

        [$ipListType, $ipHeader, $hostList] = $this->getIpList($request, $trustedHostData[self::DATA_KEY_IP_HEADERS]);
        $hostList = array_reverse($hostList); // The first item should be the closest to the server.

        if ($ipListType === self::IP_HEADER_TYPE_RFC7239) {
            $hostList = $this->getElementsByRfc7239($hostList);
        } else {
            $hostList = $this->getFormattedIpList($hostList);
        }

        $hostData = ['ip' => $actualHost];
        array_unshift($hostList, $hostData); // Move server's IP to the first position.
        $hostDataListRemaining = $hostList;
        $hostDataListValidated = [];
        $hostsCount = 0;

        do {
            $hostsCount++;

            $rawHostData = array_shift($hostDataListRemaining);
            if (!isset($rawHostData['ip'])) {
                $rawHostData = $this->reverseObfuscate(
                    $rawHostData,
                    $hostDataListValidated,
                    $hostDataListRemaining,
                    $request,
                );
                if ($rawHostData === null) {
                    continue;
                }

                if (!isset($rawHostData['ip'])) {
                    break;
                }
            }

            $ip = $rawHostData['ip'];
            if (!$this->isValidHost($ip)) {
                // Invalid IP.
                break;
            }

            if ($hostsCount >= 3) {
                $hostData = $rawHostData;
            }

            $hostDataListValidated[] = $hostData;

            if (!$this->isValidHost($ip, $trustedHostData[self::DATA_KEY_HOSTS])) {
                // Not trusted host.
                break;
            }

            $hostData = $rawHostData;
        } while (count($hostDataListRemaining) > 0);

        if ($this->attributeIps !== null) {
            $request = $request->withAttribute($this->attributeIps, $hostDataListValidated);
        }

        $uri = $request->getUri();
        // Find HTTP host.
        foreach ($trustedHostData[self::DATA_KEY_HOST_HEADERS] as $hostHeader) {
            if (!$request->hasHeader($hostHeader)) {
                continue;
            }

            if ($hostHeader === $ipHeader && $ipListType === self::IP_HEADER_TYPE_RFC7239) {
                if (!isset($hostData['httpHost'])) {
                    continue;
                }

                $host = $hostData['httpHost'];
            } else {
                $host = $request->getHeaderLine($hostHeader);
            }

            if (filter_var($host, FILTER_VALIDATE_DOMAIN) !== false) {
                $uri = $uri->withHost($host);

                break;
            }
        }

        // Find protocol.
        /** @psalm-var ProtocolHeadersData $protocolHeadersData */
        $protocolHeadersData = $trustedHostData[self::DATA_KEY_PROTOCOL_HEADERS];
        foreach ($protocolHeadersData as $protocolHeader => $protocolMap) {
            if (!$request->hasHeader($protocolHeader)) {
                continue;
            }

            if ($protocolHeader === $ipHeader && $ipListType === self::IP_HEADER_TYPE_RFC7239) {
                if (!isset($hostData['protocol'])) {
                    continue;
                }

                $protocolHeaderValue = $hostData['protocol'];
            } else {
                $protocolHeaderValue = $request->getHeaderLine($protocolHeader);
            }

            foreach ($protocolMap as $protocol => $acceptedValues) {
                if (in_array($protocolHeaderValue, $acceptedValues, true)) {
                    $uri = $uri->withScheme($protocol);

                    break 2;
                }
            }
        }

        $urlParts = $this->getUrl($request, $trustedHostData[self::DATA_KEY_URL_HEADERS]);

        if ($urlParts !== null) {
            [$path, $query] = $urlParts;
            if ($path !== null) {
                $uri = $uri->withPath($path);
            }

            if ($query !== null) {
                $uri = $uri->withQuery($query);
            }
        }

        // Find port.
        foreach ($trustedHostData[self::DATA_KEY_PORT_HEADERS] as $portHeader) {
            if (!$request->hasHeader($portHeader)) {
                continue;
            }

            if (
                $portHeader === $ipHeader
                && $ipListType === self::IP_HEADER_TYPE_RFC7239
                && isset($hostData['port'])
                && $this->checkPort((string) $hostData['port'])
            ) {
                $uri = $uri->withPort((int) $hostData['port']);
                break;
            }

            $port = $request->getHeaderLine($portHeader);

            if ($this->checkPort($port)) {
                $uri = $uri->withPort((int) $port);
                break;
            }
        }

        return $handler->handle(
            $request->withUri($uri)->withAttribute(self::REQUEST_CLIENT_IP, $hostData['ip'] ?? null),
        );
    }

    /**
     * Validate host by range.
     *
     * You can overwrite this method in a subclass to support reverse DNS verification.
     *
     * @param string[] $ranges
     * @psalm-param Closure(string, string[]): Result $validator
     */
    protected function isValidHost(string $host, array $ranges = []): bool
    {
        return $this
            ->validator
            ->validate($host, [new Ip(ranges: $ranges)])
            ->isValid();
    }

    /**
     * Reverse obfuscating host data
     *
     * RFC 7239 allows using obfuscated host data. In this case, either specifying the
     * IP address or dropping the proxy endpoint is required to determine validated route.
     *
     * By default, it doesn't perform any transformation on the data. You can override this method.
     *
     * @return array|null reverse obfuscated host data or null.
     * In case of `null` data is discarded, and the process continues with the next portion of host data.
     * If the return value is an array, it must contain at least the `ip` key.
     *
     * @psalm-param HostData|null $hostData
     *
     * @psalm-return HostData|null
     *
     * @see getElementsByRfc7239()
     * @link https://tools.ietf.org/html/rfc7239#section-6.2
     * @link https://tools.ietf.org/html/rfc7239#section-6.3
     */
    protected function reverseObfuscate(
        ?array $hostData,
        array $hostDataListValidated,
        array $hostDataListRemaining,
        RequestInterface $request
    ): ?array {
        return $hostData;
    }

    private function handleNotTrusted(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        if ($this->attributeIps !== null) {
            $request = $request->withAttribute($this->attributeIps, null);
        }

        return $handler->handle($request->withAttribute(self::REQUEST_CLIENT_IP, null));
    }

    /**
     * @psalm-return ProtocolHeadersData
     */
    private function prepareProtocolHeaders(array $protocolHeaders): array
    {
        $output = [];

        foreach ($protocolHeaders as $header => $protocolAndAcceptedValues) {
            if (!is_string($header)) {
                throw new InvalidArgumentException('The protocol header array key must be a string.');
            }

            $header = strtolower($header);

            if (is_callable($protocolAndAcceptedValues)) {
                $protocolAndAcceptedValues = $protocolAndAcceptedValues();
            }

            if (!is_array($protocolAndAcceptedValues)) {
                throw new InvalidArgumentException(
                    'Accepted values for protocol headers must be either an array or a callable returning array.',
                );
            }

            if (empty($protocolAndAcceptedValues)) {
                throw new InvalidArgumentException('Accepted values for protocol headers cannot be an empty array.');
            }

            $output[$header] = [];

            /** @psalm-var array<string|string[]> $protocolAndAcceptedValues */
            foreach ($protocolAndAcceptedValues as $protocol => $acceptedValues) {
                if (!is_string($protocol)) {
                    throw new InvalidArgumentException('The protocol must be a string.');
                }

                if ($protocol === '') {
                    throw new InvalidArgumentException('The protocol must be non-empty string.');
                }

                $output[$header][$protocol] = (array) $acceptedValues;
            }
        }

        return $output;
    }

    /**
     * @param string[] $headers
     */
    private function removeHeaders(ServerRequestInterface $request, array $headers): ServerRequestInterface
    {
        foreach ($headers as $header) {
            $request = $request->withoutHeader($header);
        }

        return $request;
    }

    /**
     * @param array<string|string[]> $ipHeaders
     *
     * @return array{0: string|null, 1: string|null, 2: string[]}
     */
    private function getIpList(ServerRequestInterface $request, array $ipHeaders): array
    {
        foreach ($ipHeaders as $ipHeader) {
            $type = null;

            if (is_array($ipHeader)) {
                $type = array_shift($ipHeader);
                $ipHeader = array_shift($ipHeader);
            }

            if ($request->hasHeader($ipHeader)) {
                return [$type, $ipHeader, $request->getHeader($ipHeader)];
            }
        }

        return [null, null, []];
    }

    /**
     * @param string[] $forwards
     *
     * @psalm-return list<HostData>
     *
     * @see getElementsByRfc7239()
     */
    private function getFormattedIpList(array $forwards): array
    {
        $list = [];

        foreach ($forwards as $ip) {
            $list[] = ['ip' => $ip];
        }

        return $list;
    }

    /**
     * Forwarded elements by RFC7239.
     *
     * The structure of the elements:
     * - `host`: IP or obfuscated hostname or "unknown"
     * - `ip`: IP address (only if presented)
     * - `by`: used user-agent by proxy (only if presented)
     * - `port`: port number received by proxy (only if presented)
     * - `protocol`: protocol received by proxy (only if presented)
     * - `httpHost`: HTTP host received by proxy (only if presented)
     *
     * The list starts with the server, and the last item is the client itself.
     *
     * @link https://tools.ietf.org/html/rfc7239
     *
     * @param string[] $forwards
     *
     * @psalm-return list<HostData> Proxy data elements.
     */
    private function getElementsByRfc7239(array $forwards): array
    {
        $list = [];

        foreach ($forwards as $forward) {
            try {
                /** @psalm-var array<string, string> $data */
                $data = HeaderValueHelper::getParameters($forward);
            } catch (InvalidArgumentException) {
                break;
            }

            if (!isset($data['for'])) {
                // Invalid item, the following items will be dropped.
                break;
            }

            $pattern = '/^(?<host>' . IpHelper::IPV4_PATTERN . '|unknown|_[\w.-]+|[[]'
                . IpHelper::IPV6_PATTERN . '[]])(?::(?<port>[\w.-]+))?$/';

            if (preg_match($pattern, $data['for'], $matches) === 0) {
                // Invalid item, the following items will be dropped.
                break;
            }

            $ipData = [];
            $host = $matches['host'];
            $obfuscatedHost = $host === 'unknown' || str_starts_with($host, '_');

            if (!$obfuscatedHost) {
                // IPv4 & IPv6.
                $ipData['ip'] = str_starts_with($host, '[') ? trim($host /* IPv6 */, '[]') : $host;
            }

            $ipData['host'] = $host;

            if (isset($matches['port'])) {
                $port = $matches['port'];

                if (!$obfuscatedHost && !$this->checkPort($port)) {
                    // Invalid port, the following items will be dropped.
                    break;
                }

                $ipData['port'] = $obfuscatedHost ? $port : (int) $port;
            }

            // Copy other properties.
            foreach (['proto' => 'protocol', 'host' => 'httpHost', 'by' => 'by'] as $source => $destination) {
                if (isset($data[$source])) {
                    $ipData[$destination] = $data[$source];
                }
            }

            if (isset($ipData['httpHost']) && filter_var($ipData['httpHost'], FILTER_VALIDATE_DOMAIN) === false) {
                // Remove not valid HTTP host.
                unset($ipData['httpHost']);
            }

            $list[] = $ipData;
        }

        return $list;
    }

    /**
     * @param string[] $urlHeaders
     *
     * @psalm-return non-empty-list<null|string>|null
     */
    private function getUrl(RequestInterface $request, array $urlHeaders): ?array
    {
        foreach ($urlHeaders as $header) {
            if (!$request->hasHeader($header)) {
                continue;
            }

            $url = $request->getHeaderLine($header);

            if (!str_starts_with($url, '/')) {
                continue;
            }

            $urlParts = explode('?', $url, 2);
            if (!isset($urlParts[1])) {
                $urlParts[] = null;
            }

            return $urlParts;
        }

        return null;
    }

    private function checkPort(string $port): bool
    {
        /**
         * @infection-ignore-all
         * - PregMatchRemoveCaret.
         * - PregMatchRemoveDollar.
         */
        if (preg_match('/^\d{1,5}$/', $port) !== 1) {
            return false;
        }

        /** @infection-ignore-all CastInt */
        $intPort = (int) $port;

        return $intPort >= 1 && $intPort <= 65535;
    }

    /**
     * @psalm-assert array<non-empty-string> $array
     */
    private function requireListOfNonEmptyStrings(array $array, string $arrayName): void
    {
        foreach ($array as $item) {
            if (!is_string($item)) {
                throw new InvalidArgumentException("Each \"$arrayName\" item must be string.");
            }

            if (trim($item) === '') {
                throw new InvalidArgumentException("Each \"$arrayName\" item must be non-empty string.");
            }
        }
    }
}
