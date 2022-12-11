<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Middleware\Tests;

use InvalidArgumentException;
use HttpSoft\Message\ServerRequest;
use PHPUnit\Framework\TestCase;
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
            'rfc7239Level2HostAndProtocol' => [
                ['forwarded' => ['for=9.9.9.9', 'proto=https;for=5.5.5.5;host=test', 'for=2.2.2.2']],
                ['REMOTE_ADDR' => '127.0.0.1'],
                [
                    [
                        'hosts' => ['8.8.8.8', '127.0.0.1', '2.2.2.2'],
                        'ipHeaders' => [[TrustedHostsNetworkResolver::IP_HEADER_TYPE_RFC7239, 'forwarded']],
                        'hostHeaders' => ['forwarded'],
                        'protocolHeaders' => ['forwarded' => ['http' => 'http', 'https' => 'https']],
                    ],
                ],
                '5.5.5.5',
                'test',
                'https',
            ],
            'rfc7239Level2HostAndProtocolAndUrl' => [
                [
                    'forwarded' => ['for=9.9.9.9', 'proto=https;for=5.5.5.5;host=test', 'for=2.2.2.2'],
                    'x-rewrite-url' => ['/test?test=test'],
                ],
                ['REMOTE_ADDR' => '127.0.0.1'],
                [
                    [
                        'hosts' => ['8.8.8.8', '127.0.0.1', '2.2.2.2'],
                        'ipHeaders' => [[TrustedHostsNetworkResolver::IP_HEADER_TYPE_RFC7239, 'forwarded']],
                        'hostHeaders' => ['forwarded'],
                        'protocolHeaders' => ['forwarded' => ['http' => 'http', 'https' => 'https']],
                        'urlHeaders' => ['x-rewrite-url'],
                    ],
                ],
                '5.5.5.5',
                'test',
                'https',
                '/test',
                'test=test',
            ],
            'rfc7239Level2AnotherHost&AnotherProtocol&Url' => [
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
            'rfc7239Level2AnotherHost&AnotherProtocol&Url&Port' => [
                [
                    'forwarded' => ['for=9.9.9.9', 'proto=https;for="5.5.5.5:123";host=test', 'for=2.2.2.2'],
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
                        'portHeaders' => ['forwarded'],
                    ],
                ],
                '5.5.5.5',
                'test.another',
                'https',
                '/test',
                'test=test',
                123,
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
        $middleware = $this->createTrustedHostsNetworkResolver();

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
                ['REMOTE_ADDR' => '127.0.0.1'],
                [],
            ],
            'x-forwarded-for' => [
                ['x-forwarded-for' => ['9.9.9.9', '5.5.5.5', '2.2.2.2']],
                ['REMOTE_ADDR' => '127.0.0.1'],
                [['hosts' => ['8.8.8.8'], 'ipHeaders' => ['x-forwarded-for']]],
            ],
            'rfc7239' => [
                ['x-forwarded-for' => ['for=9.9.9.9', 'for=5.5.5.5', 'for=2.2.2.2']],
                ['REMOTE_ADDR' => '127.0.0.1'],
                [['hosts' => ['8.8.8.8'], 'ipHeaders' => ['x-forwarded-for']]],
            ],
        ];
    }

    /**
     * @dataProvider notTrustedDataProvider
     */
    public function testNotTrusted(array $headers, array $serverParams, array $trustedHosts): void
    {
        $request = $this->createRequestWithSchemaAndHeaders('http', $headers, $serverParams);
        $requestHandler = new MockRequestHandler();
        $middleware = $this->createTrustedHostsNetworkResolver();

        foreach ($trustedHosts as $data) {
            $middleware = $middleware->withAddedTrustedHosts(
                $data['hosts'],
                $data['ipHeaders'] ?? [],
                $data['protocolHeaders'] ?? [],
                [],
                [],
                [],
                $data['trustedHeaders'] ?? [],
            );
        }
        $middleware->process($request, $requestHandler);
        $this->assertNull($request->getAttribute('requestClientIp'));
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
                true,
            ],
            'protocolHeadersNumeric' => [
                [
                    'hosts' => ['127.0.0.1'],
                    'protocolHeaders' => ['x-forwarded-proto' => 888],
                ],
                true,
            ],
            'protocolHeadersKeyItemNumeric' => [
                [
                    'hosts' => ['127.0.0.1'],
                    'protocolHeaders' => ['x-forwarded-proto' => [888 => 'http']],
                ],
                true,
            ],
            'protocolHeadersKeyItemEmptyString' => [
                [
                    'hosts' => ['127.0.0.1'],
                    'protocolHeaders' => ['x-forwarded-proto' => ['' => 'http']],
                ],
                true,
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
    public function testAddedTrustedHostsInvalidParameter(array $data, bool $isRuntimeException = false): void
    {
        $this->expectException($isRuntimeException ? RuntimeException::class : InvalidArgumentException::class);

        ($this->createTrustedHostsNetworkResolver())
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
        $response = ($this->createTrustedHostsNetworkResolver())->withAttributeIps('ip')->process($request, $requestHandler);

        $this->assertSame(Status::OK, $response->getStatusCode());
        $this->assertSame('', $requestHandler->processedRequest->getUri()->getHost());
        $this->assertNull($requestHandler->processedRequest->getAttribute('ip', 'default'));
        $this->assertNull($requestHandler->processedRequest->getAttribute('requestClientIp', 'default'));
    }

    public function testAttributeIpsInvalidWhenEmptyString(): void
    {
        $this->expectException(RuntimeException::class);

        ($this->createTrustedHostsNetworkResolver())->withAttributeIps('');
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
        return new TrustedHostsNetworkResolver(new Validator());
    }
}
