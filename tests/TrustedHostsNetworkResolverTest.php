<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Middleware\Tests;

use InvalidArgumentException;
use HttpSoft\Message\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Yiisoft\Http\Status;
use Yiisoft\Validator\Validator;
use Yiisoft\Yii\Middleware\TrustedHostsNetworkResolver;
use Yiisoft\Yii\Middleware\Tests\TestAsset\MockRequestHandler;

final class TrustedHostsNetworkResolverTest extends TestCase
{
    public function trustedDataProvider(): array
    {
        $serverParams = ['REMOTE_ADDR' => '127.0.0.1'];

        $obfuscatedHostsTrustedHosts = [
            [
                'hosts' => ['8.8.8.8', '127.0.0.1', '2.2.2.2'],
                'ipHeaders' => [[TrustedHostsNetworkResolver::IP_HEADER_TYPE_RFC7239, 'forwarded']],
            ],
        ];

        $urlHeaders = ['forwarded' => ['for=9.9.9.9', 'proto=https;for=5.5.5.5;host=test', 'for=2.2.2.2']];
        $urlTrustedHosts = [
            [
                'hosts' => ['8.8.8.8', '127.0.0.1', '2.2.2.2'],
                'ipHeaders' => [[TrustedHostsNetworkResolver::IP_HEADER_TYPE_RFC7239, 'forwarded']],
                'hostHeaders' => ['forwarded'],
                'protocolHeaders' => ['forwarded' => fn () => ['http' => 'http', 'https' => 'https']],
                'urlHeaders' => ['non-existing-header', 'x-rewrite-url'],
            ],
        ];

        $wrongPortsHeaders = [
            'x-rewrite-url' => ['/test?test=test'],
            'x-forwarded-host' => ['test.another'],
            'x-forwarded-proto' => ['on'],
        ];
        $wrongPortsTrustedHosts = [
            [
                'hosts' => ['8.8.8.8', '127.0.0.1', '2.2.2.2'],
                'ipHeaders' => [[TrustedHostsNetworkResolver::IP_HEADER_TYPE_RFC7239, 'forwarded']],
                'hostHeaders' => ['x-forwarded-host', 'forwarded'],
                'protocolHeaders' => [
                    'x-forwarded-proto' => ['http' => 'http'],
                    'forwarded' => ['http' => 'http', 'https' => 'https'],
                ],
                'urlHeaders' => ['x-rewrite-url'],
                'portHeaders' => ['x-forwarded-port', 'forwarded'],
            ],
        ];

        return [
            'xForwardLevel1' => [
                ['x-forwarded-for' => ['9.9.9.9', '5.5.5.5', '2.2.2.2']],
                ['REMOTE_ADDR' => '127.0.0.1'],
                [
                    ['hosts' => ['8.8.8.8', '127.0.0.1'], 'ipHeaders' => ['x-forwarded-for']],
                ],
                '2.2.2.2',
            ],
            'xForwardLevel2' => [
                ['x-forwarded-for' => ['9.9.9.9', '5.5.5.5', '2.2.2.2']],
                ['REMOTE_ADDR' => '127.0.0.1'],
                [
                    ['hosts' => ['8.8.8.8', '127.0.0.1', '2.2.2.2'], 'ipHeaders' => ['x-forwarded-for']],
                ],
                '5.5.5.5',
            ],
            'xForwardLevel3' => [
                ['x-forwarded-for' => ['9.9.9.9', '5.5.5.5', '2.2.2.2']],
                ['REMOTE_ADDR' => '127.0.0.1'],
                [
                    ['hosts' => ['8.8.8.8', '127.0.0.1', '2.2.2.2'], 'ipHeaders' => ['x-forwarded-for']],
                    ['hosts' => ['172.16.0.1', '127.0.0.1'], 'ipHeaders' => ['x-forwarded-for']],
                ],
                '5.5.5.5',
            ],
            'xForwardLevel4' => [
                ['x-forwarded-for' => ['9.9.9.9', '5.5.5.5', '2.2.2.2']],
                ['REMOTE_ADDR' => '127.0.0.1'],
                [
                    ['hosts' => ['172.16.0.1', '127.0.0.1'], 'ipHeaders' => []],
                ],
                '127.0.0.1',
            ],
            'xForwardLevel5' => [
                ['x-forwarded-for' => ['1234']],
                ['REMOTE_ADDR' => '127.0.0.1'],
                [
                    ['hosts' => ['172.16.0.1', '127.0.0.1'], 'ipHeaders' => [], 'portHeaders' => ['x-forwarded-for']],
                ],
                '127.0.0.1',
                '',
                'http',
                '/',
                '',
                1234,
            ],
            'xForwardLevel6' => [
                ['x-forwarded-proto' => ['https']],
                ['REMOTE_ADDR' => '127.0.0.1'],
                [
                    [
                        'hosts' => ['172.16.0.1', '127.0.0.1'],
                        'protocolHeaders' => ['x-forwarded-proto' => ['http' => 'http', 'https' => 'https']],
                    ],
                ],
                '127.0.0.1',
                '',
                'https',
            ],
            'rfc7239Level1' => [
                ['forwarded' => ['for=9.9.9.9', 'for=5.5.5.5', 'for=2.2.2.2']],
                ['REMOTE_ADDR' => '127.0.0.1'],
                [
                    [
                        'hosts' => ['8.8.8.8', '127.0.0.1'],
                        'ipHeaders' => [[TrustedHostsNetworkResolver::IP_HEADER_TYPE_RFC7239, 'forwarded']],
                    ],
                ],
                '2.2.2.2',
            ],
            'rfc7239Level2' => [
                ['forwarded' => ['for=9.9.9.9', 'for=5.5.5.5', 'for=2.2.2.2']],
                ['REMOTE_ADDR' => '127.0.0.1'],
                [
                    [
                        'hosts' => ['8.8.8.8', '127.0.0.1', '2.2.2.2'],
                        'ipHeaders' => [[TrustedHostsNetworkResolver::IP_HEADER_TYPE_RFC7239, 'forwarded']],
                    ],
                ],
                '5.5.5.5',
            ],
            'rfc7239, level 2, obfuscated host, unknown' => [
                ['forwarded' => ['for=unknown', 'to=unknown']],
                $serverParams,
                $obfuscatedHostsTrustedHosts,
                '127.0.0.1',
            ],
            'rfc7239, level 2, obfuscated host, unknown, with port' => [
                ['forwarded' => ['for=unknown:1']],
                $serverParams,
                $obfuscatedHostsTrustedHosts,
                '127.0.0.1',
            ],
            //            'rfc7239, level 2, obfuscated host, starts witn underscore' => [
            //                ['forwarded' => ['for=_hidden', 'for=_SEVKISEK']],
            //                $serverParams,
            //                $obfuscatedHostsTrustedHosts,
            //                '127.0.0.1',
            //            ],
            'rfc7239Level3' => [
                ['forwarded' => ['to=9.9.9.9', 'for=5.5.5.5', 'for=2.2.2.2']],
                ['REMOTE_ADDR' => '127.0.0.1'],
                [
                    [
                        'hosts' => ['8.8.8.8', '127.0.0.1', '2.2.2.2'],
                        'ipHeaders' => [[TrustedHostsNetworkResolver::IP_HEADER_TYPE_RFC7239, 'forwarded']],
                    ],
                ],
                '5.5.5.5',
            ],
            'rfc7239Level4ContainsInvalidIp' => [
                ['forwarded' => ['for=invalid9.9.9.9', 'for=5.5.5.5', 'for=2.2.2.2']],
                ['REMOTE_ADDR' => '127.0.0.1'],
                [
                    [
                        'hosts' => ['8.8.8.8', '127.0.0.1', '2.2.2.2'],
                        'ipHeaders' => [[TrustedHostsNetworkResolver::IP_HEADER_TYPE_RFC7239, 'forwarded']],
                    ],
                ],
                '5.5.5.5',
            ],
            'rfc7239Level5HostAndProtocol' => [
                ['forwarded' => ['for=9.9.9.9', 'proto=https;for=5.5.5.5;host=test', 'for=2.2.2.2']],
                ['REMOTE_ADDR' => '127.0.0.1'],
                [
                    [
                        'hosts' => ['8.8.8.8', '127.0.0.1', '2.2.2.2'],
                        'ipHeaders' => [[TrustedHostsNetworkResolver::IP_HEADER_TYPE_RFC7239, 'forwarded']],
                        'hostHeaders' => ['x-forwarded-host', 'forwarded'],
                        'protocolHeaders' => [
                            'x-forwarded-proto' => ['http' => 'http'],
                            'forwarded' => ['http' => 'http', 'https' => 'https'],
                        ],
                    ],
                ],
                '5.5.5.5',
                'test',
                'https',
            ],
            'rfc7239, level 6, host, protocol, url with query parameters' => [
                array_merge($urlHeaders, [
                    'x-rewrite-url' => ['/test?test=test'],
                ]),
                $serverParams,
                $urlTrustedHosts,
                '5.5.5.5',
                'test',
                'https',
                '/test',
                'test=test',
            ],
            'rfc7239, level 6, host, protocol, url without query parameters' => [
                array_merge($urlHeaders, [
                    'x-rewrite-url' => ['/test'],
                ]),
                $serverParams,
                $urlTrustedHosts,
                '5.5.5.5',
                'test',
                'https',
                '/test',
                '',
            ],
            'rfc7239, level 6, host, protocol, url with badly formed query parameters' => [
                array_merge($urlHeaders, [
                    'x-rewrite-url' => ['/test?param1=val1?param2=val2'],
                ]),
                $serverParams,
                $urlTrustedHosts,
                '5.5.5.5',
                'test',
                'https',
                '/test',
                'param1=val1?param2=val2',
            ],
            'rfc7239Level7AnotherHost&AnotherProtocol&Url' => [
                [
                    'forwarded' => ['for=9.9.9.9', 'proto=https;for=5.5.5.5;host=test', 'for=2.2.2.2'],
                    'x-rewrite-url' => ['/test?test=test'],
                    'x-forwarded-host' => ['test.another'],
                    'x-forwarded-proto' => ['on'],
                ],
                ['REMOTE_ADDR' => '127.0.0.1'],
                [
                    [
                        'hosts' => ['8.8.8.8', '127.0.0.1', '2.2.2.2'],
                        'ipHeaders' => [[TrustedHostsNetworkResolver::IP_HEADER_TYPE_RFC7239, 'forwarded']],
                        'hostHeaders' => ['x-forwarded-host', 'forwarded'],
                        'protocolHeaders' => [
                            'x-forwarded-proto' => ['http' => 'http'],
                            'forwarded' => ['http' => 'http', 'https' => 'https'],
                        ],
                        'urlHeaders' => ['x-rewrite-url'],
                    ],
                ],
                '5.5.5.5',
                'test.another',
                'https',
                '/test',
                'test=test',
            ],
            'rfc7239, level 8, another host, another protocol, url, ports (string, valid, missing)' => [
                array_merge($wrongPortsHeaders, [
                    'forwarded' => ['for="9.9.9.9:abs"', 'proto=https;for="5.5.5.5:123";host=test', 'for=2.2.2.2'],
                ]),
                $serverParams,
                $wrongPortsTrustedHosts,
                '5.5.5.5',
                'test.another',
                'https',
                '/test',
                'test=test',
                123,
            ],
            'rfc7239, level 8, another host, another protocol, url, ports (greater than max by 1, long, min allowed)' => [
                array_merge($wrongPortsHeaders, [
                    'forwarded' => [
                        'for="9.9.9.9:65536"',
                        'proto=https;for="5.5.5.5:123456";host=test',
                        'for="2.2.2.2:1"',
                    ],
                ]),
                $serverParams,
                $wrongPortsTrustedHosts,
                '2.2.2.2',
                'test.another',
                'http',
                '/test',
                'test=test',
                1,
            ],
            'rfc7239, level 8, another host, another protocol, url, ports (less than min by 1, long, max allowed)' => [
                array_merge($wrongPortsHeaders, [
                    'forwarded' => [
                        'for="9.9.9.9:0"',
                        'proto=https;for="5.5.5.5:123456";host=test',
                        'for="2.2.2.2:65535"',
                    ],
                ]),
                $serverParams,
                $wrongPortsTrustedHosts,
                '2.2.2.2',
                'test.another',
                'http',
                '/test',
                'test=test',
                65535,
            ],
            'trustedHeaders' => [
                ['x-forwarded-for' => ['9.9.9.9', '5.5.5.5', '2.2.2.2'], 'foo' => 'bar'],
                ['REMOTE_ADDR' => '127.0.0.1'],
                [
                    [
                        'hosts' => ['8.8.8.8', '127.0.0.1'],
                        'ipHeaders' => ['x-forwarded-for'],
                        'trustedHeaders' => ['x-forwarded-for'],
                    ],
                ],
                '2.2.2.2',
            ],
        ];
    }

    /**
     * @dataProvider trustedDataProvider
     */
    public function testTrusted(
        array $headers,
        array $serverParams,
        array $trustedHosts,
        string $expectedClientIp,
        ?string $expectedHttpHost = null,
        string $expectedHttpScheme = 'http',
        string $expectedPath = '/',
        string $expectedQuery = '',
        ?int $expectedPort = null
    ): void {
        $request = $this->createRequestWithSchemaAndHeaders('http', $headers, $serverParams);
        $requestHandler = new MockRequestHandler();
        $middleware = in_array('for=unknown', $headers['forwarded'] ?? [], true) ?
            $this->createCustomTrustedHostsNetworkResolver() :
            $this->createTrustedHostsNetworkResolver();

        foreach ($trustedHosts as $data) {
            $middleware = $middleware->withAddedTrustedHosts(
                $data['hosts'],
                $data['ipHeaders'] ?? [],
                $data['protocolHeaders'] ?? [],
                $data['hostHeaders'] ?? [],
                $data['urlHeaders'] ?? [],
                $data['portHeaders'] ?? [],
                $data['trustedHeaders'] ?? null
            );
        }

        $response = $middleware->process($request, $requestHandler);

        if ($expectedHttpHost !== null) {
            $this->assertSame($expectedHttpHost, $requestHandler->processedRequest->getUri()->getHost());
        }

        $this->assertSame(Status::OK, $response->getStatusCode());
        $this->assertSame($expectedClientIp, $requestHandler->processedRequest->getAttribute('requestClientIp'));
        $this->assertSame($expectedHttpScheme, $requestHandler->processedRequest->getUri()->getScheme());
        $this->assertSame($expectedPath, $requestHandler->processedRequest->getUri()->getPath());
        $this->assertSame($expectedQuery, $requestHandler->processedRequest->getUri()->getQuery());
        $this->assertSame($expectedPort, $requestHandler->processedRequest->getUri()->getPort());
    }

    public function notTrustedDataProvider(): array
    {
        return [
            'none' => [
                [],
                [],
            ],
            'x-forwarded-for' => [
                ['x-forwarded-for' => ['9.9.9.9', '5.5.5.5', '2.2.2.2']],
                ['hosts' => ['8.8.8.8'], 'ipHeaders' => ['x-forwarded-for']],
            ],
            'rfc7239' => [
                ['x-forwarded-for' => ['for=9.9.9.9', 'for=5.5.5.5', 'for=2.2.2.2']],
                ['hosts' => ['8.8.8.8'], 'ipHeaders' => ['x-forwarded-for']],
            ],
        ];
    }

    /**
     * @dataProvider notTrustedDataProvider
     */
    public function testNotTrusted(array $headers, array $trustedHostsData): void
    {
        $middleware = $this->createTrustedHostsNetworkResolver();

        if ($trustedHostsData !== []) {
            $middleware = $middleware->withAddedTrustedHosts(
                $trustedHostsData['hosts'],
                $trustedHostsData['ipHeaders'],
            );
        }

        $request = $this->createRequestWithSchemaAndHeaders(
            headers: $headers,
            serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
        );
        $requestHandler = new MockRequestHandler();

        $middleware->process($request, $requestHandler);
        $this->assertNull($request->getAttribute(TrustedHostsNetworkResolver::REQUEST_CLIENT_IP));
    }

    public function dataWithAddedTrustedHostsAndWrongHeader(): array
    {
        return [
            'empty hosts' => [
                ['hosts' => []],
                'Empty hosts are not allowed.',
            ],
            [
                ['ipHeaders' => ['x-forwarded-for', 1]],
                'IP header must have either string or array type.',
            ],
            [
                ['ipHeaders' => [['a', 'b', 'c']]],
                'IP header array must have exactly 2 elements.',
            ],
            [
                ['ipHeaders' => [[1, 'b']]],
                'IP header type must be a string.',
            ],
            [
                ['ipHeaders' => [['a', 2]]],
                'IP header value must be a string.',
            ],
            [
                ['ipHeaders' => [[TrustedHostsNetworkResolver::IP_HEADER_TYPE_RFC7239, 'header'], ['a', 'header']]],
                'Not supported IP header type: "a".',
            ],
        ];
    }

    /**
     * @dataProvider dataWithAddedTrustedHostsAndWrongHeader
     */
    public function testWithAddedTrustedHostsAndWrongArguments(
        array $trustedHostsData,
        string $expectedExceptionMessage,
    ): void {
        $middleware = $this->createTrustedHostsNetworkResolver();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedExceptionMessage);
        $middleware->withAddedTrustedHosts(
            $trustedHostsData['hosts'] ?? ['9.9.9.9', '5.5.5.5', '2.2.2.2'],
            $trustedHostsData['ipHeaders'] ?? [],
        );
    }

    public function addedTrustedHostsInvalidParameterDataProvider(): array
    {
        return [
            'hostsEmpty' => [['hosts' => []]],
            'hostsEmptyString' => [['hosts' => ['']]],
            'hostsNumeric' => [['hosts' => [888]]],
            'hostsSpaces' => [['hosts' => ['    ']]],
            'hostsNotDomain' => [['hosts' => ['11111111111111111111111111111111111111111111111111111111111111111111']]],
            'urlHeadersEmpty' => [['urlHeaders' => ['']]],
            'urlHeadersNumeric' => [['urlHeaders' => [888]]],
            'urlHeadersSpaces' => [['urlHeaders' => ['   ']]],
            'trustedHeadersEmpty' => [['trustedHeaders' => ['']]],
            'trustedHeadersNumeric' => [['trustedHeaders' => [888]]],
            'trustedHeadersSpaces' => [['trustedHeaders' => ['   ']]],
            'protocolHeadersEmptyArray' => [
                [
                    'hosts' => ['127.0.0.1'],
                    'protocolHeaders' => ['x-forwarded-proto' => []],
                ],
            ],
            'protocolHeadersNumericValue' => [
                [
                    'hosts' => ['127.0.0.1'],
                    'protocolHeaders' => ['x-forwarded-proto' => 888],
                ],
            ],
            'protocolHeadersWithoutAcceptedData' => [
                [
                    'hosts' => ['127.0.0.1'],
                    'protocolHeaders' => ['x-forwarded-proto'],
                ],
            ],
            'protocolHeadersKeyItemNumeric' => [
                [
                    'hosts' => ['127.0.0.1'],
                    'protocolHeaders' => ['x-forwarded-proto' => [888 => 'http']],
                ],
            ],
            'protocolHeadersKeyItemEmptyString' => [
                [
                    'hosts' => ['127.0.0.1'],
                    'protocolHeaders' => ['x-forwarded-proto' => ['' => 'http']],
                ],
            ],
            'ipHeadersEmptyString' => [['ipHeaders' => [' ']]],
            'ipHeadersNumeric' => [['ipHeaders' => [888]]],
            'ipHeadersNotSupportedIpHeaderType' => [['ipHeaders' => [['---', 'aaa']]]],
            'ipHeadersInvalidIpHeaderType' => [
                [
                    'ipHeaders' => [
                        [
                            888,
                            'aaa',
                        ],
                    ],
                ],
            ],
            'ipHeadersInvalidIpHeaderValue' => [
                [
                    'ipHeaders' => [
                        [
                            TrustedHostsNetworkResolver::IP_HEADER_TYPE_RFC7239,
                            888,
                        ],
                    ],
                ],
            ],
            'ipHeadersOnlyIpHeaderTypeWithoutIpHeaderValue' => [
                [
                    'ipHeaders' => [
                        [
                            TrustedHostsNetworkResolver::IP_HEADER_TYPE_RFC7239,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @dataProvider addedTrustedHostsInvalidParameterDataProvider
     */
    public function testAddedTrustedHostsInvalidParameter(array $data): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->createTrustedHostsNetworkResolver()
            ->withAddedTrustedHosts(
                $data['hosts'] ?? [],
                $data['ipHeaders'] ?? [],
                $data['protocolHeaders'] ?? [],
                $data['hostHeaders'] ?? [],
                $data['urlHeaders'] ?? [],
                $data['portHeaders'] ?? [],
                $data['trustedHeaders'] ?? null
            );
    }

    public function testProcessWithAttributeIpsAndWithoutActualHost(): void
    {
        $request = $this->createRequestWithSchemaAndHeaders();
        $requestHandler = new MockRequestHandler();
        $response = $this->createTrustedHostsNetworkResolver()->withAttributeIps('ip')->process($request, $requestHandler);

        $this->assertSame(Status::OK, $response->getStatusCode());
        $this->assertSame('', $requestHandler->processedRequest->getUri()->getHost());
        $this->assertNull($requestHandler->processedRequest->getAttribute('ip', 'default'));
        $this->assertNull($requestHandler->processedRequest->getAttribute('requestClientIp', 'default'));
    }

    public function testAttributeIpsInvalidWhenEmptyString(): void
    {
        $this->expectException(RuntimeException::class);

        $this->createTrustedHostsNetworkResolver()->withAttributeIps('');
    }

    public function testImmutability(): void
    {
        $middleware = $this->createTrustedHostsNetworkResolver();

        $this->assertNotSame($middleware, $middleware->withAddedTrustedHosts(['8.8.8.8']));
        $this->assertNotSame($middleware, $middleware->withoutTrustedHosts());
        $this->assertNotSame($middleware, $middleware->withAttributeIps('test'));
        $this->assertNotSame($middleware, $middleware->withAttributeIps(null));
    }

    private function createRequestWithSchemaAndHeaders(
        string $scheme = 'http',
        array $headers = [],
        array $serverParams = []
    ): ServerRequestInterface {
        $request = new ServerRequest($serverParams);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $uri = $request->getUri()->withScheme($scheme)->withPath('/');
        return $request->withUri($uri);
    }

    private function createTrustedHostsNetworkResolver(): TrustedHostsNetworkResolver
    {
        return (new TrustedHostsNetworkResolver(new Validator()))->withAttributeIps('resolvedIps');
    }

    private function createCustomTrustedHostsNetworkResolver(): TrustedHostsNetworkResolver
    {
        return new class () extends TrustedHostsNetworkResolver {
            public function __construct()
            {
                parent::__construct(new Validator());
            }

            protected function reverseObfuscate(
                ?array $hostData,
                array $hostDataListValidated,
                array $hostDataListRemaining,
                RequestInterface $request
            ): ?array {
                return $hostData['host'] === 'unknown' ? ['ip' => '127.0.0.1'] : $hostData;
            }
        };
    }
}
