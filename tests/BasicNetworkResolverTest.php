<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Middleware\Tests;

use HttpSoft\Message\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Yii\Middleware\BasicNetworkResolver;
use Yiisoft\Yii\Middleware\Tests\TestAsset\MockRequestHandler;

final class BasicNetworkResolverTest extends TestCase
{
    public function schemeDataProvider(): array
    {
        return [
            'httpNotModify' => ['http', [], null, 'http'],
            'httpsNotModify' => ['https', [], null, 'https'],
            'httpNotMatchedProtocolHeader' => [
                'http',
                ['x-forwarded-proto' => ['https']],
                ['test' => ['https' => 'https']],
                'http',
            ],
            'httpNotMatchedProtocolHeaderValue' => [
                'http',
                ['x-forwarded-proto' => ['https']],
                ['x-forwarded-proto' => ['https' => 'test']],
                'http',
            ],
            'httpToHttps' => [
                'http',
                ['x-forwarded-proto' => ['https']],
                ['x-forwarded-proto' => ['https' => 'https']],
                'https',
            ],
            'httpToHttpsDefault' => [
                'http',
                ['x-forwarded-proto' => ['https']],
                ['x-forwarded-proto' => null],
                'https',
            ],
            'httpToHttpsUpperCase' => [
                'http',
                ['x-forwarded-proto' => ['https']],
                ['x-forwarded-proto' => ['https' => 'HTTPS']],
                'https',
            ],
            'httpToHttpsMultiValue' => [
                'http',
                ['x-forwarded-proto' => ['https']],
                ['x-forwarded-proto' => ['https' => ['on', 's', 'https']]],
                'https',
            ],
            'httpsToHttp' => [
                'https',
                ['x-forwarded-proto' => 'http'],
                ['x-forwarded-proto' => ['http' => 'http']],
                'http',
            ],
            'httpToHttpsWithCallback' => [
                'http',
                ['x-forwarded-proto' => 'test any-https **'],
                [
                    'x-forwarded-proto' => function (array $values, string $header, ServerRequestInterface $request) {
                        return stripos($values[0], 'https') !== false ? 'https' : 'http';
                    },
                ],
                'https',
            ],
            'httpWithCallbackNull' => [
                'http',
                ['x-forwarded-proto' => 'test any-https **'],
                [
                    'x-forwarded-proto' => function (array $values, string $header, ServerRequestInterface $request) {
                        return null;
                    },
                ],
                'http',
            ],
        ];
    }

    /**
     * @dataProvider schemeDataProvider
     */
    public function testScheme(string $scheme, array $headers, ?array $protocolHeaders, string $expectedScheme): void
    {
        $request = $this->createRequestWithSchemaAndHeaders($scheme, $headers);
        $requestHandler = new MockRequestHandler();

        $middleware = new BasicNetworkResolver();
        if ($protocolHeaders !== null) {
            foreach ($protocolHeaders as $header => $values) {
                $middleware = $middleware->withAddedProtocolHeader($header, $values);
            }
        }
        $middleware->process($request, $requestHandler);
        $resultRequest = $requestHandler->processedRequest;
        /* @var $resultRequest ServerRequestInterface */
        $this->assertSame($expectedScheme, $resultRequest->getUri()->getScheme());
    }

    public function testWithoutProtocolHeaders(): void
    {
        $request = $this->createRequestWithSchemaAndHeaders('http', [
            'x-forwarded-proto' => ['https'],
        ]);
        $requestHandler = new MockRequestHandler();

        $middleware = (new BasicNetworkResolver())
            ->withAddedProtocolHeader('x-forwarded-proto')
            ->withoutProtocolHeaders();
        $middleware->process($request, $requestHandler);
        $resultRequest = $requestHandler->processedRequest;
        /* @var $resultRequest ServerRequestInterface */
        $this->assertSame('http', $resultRequest->getUri()->getScheme());
    }

    public function testWithoutProtocolHeadersMulti(): void
    {
        $request = $this->createRequestWithSchemaAndHeaders('http', [
            'x-forwarded-proto' => ['https'],
            'x-forwarded-proto-2' => ['https'],
        ]);
        $requestHandler = new MockRequestHandler();

        $middleware = (new BasicNetworkResolver())
            ->withAddedProtocolHeader('x-forwarded-proto')
            ->withAddedProtocolHeader('x-forwarded-proto-2')
            ->withoutProtocolHeaders([
                'x-forwarded-proto',
                'x-forwarded-proto-2',
            ]);
        $middleware->process($request, $requestHandler);
        $resultRequest = $requestHandler->processedRequest;
        /* @var $resultRequest ServerRequestInterface */
        $this->assertSame('http', $resultRequest->getUri()->getScheme());
    }

    public function testWithoutProtocolHeader(): void
    {
        $request = $this->createRequestWithSchemaAndHeaders('https', [
            'x-forwarded-proto' => ['https'],
            'x-forwarded-proto-2' => ['http'],
        ]);
        $requestHandler = new MockRequestHandler();

        $middleware = (new BasicNetworkResolver())
            ->withAddedProtocolHeader('x-forwarded-proto')
            ->withAddedProtocolHeader('x-forwarded-proto-2')
            ->withoutProtocolHeader('x-forwarded-proto');
        $middleware->process($request, $requestHandler);
        $resultRequest = $requestHandler->processedRequest;
        /* @var $resultRequest ServerRequestInterface */
        $this->assertSame('http', $resultRequest->getUri()->getScheme());

        $middleware = $middleware->withoutProtocolHeader('x-forwarded-proto-2');
        $middleware->process($request, $requestHandler);
        $resultRequest = $requestHandler->processedRequest;
        /* @var $resultRequest ServerRequestInterface */
        $this->assertSame('https', $resultRequest->getUri()->getScheme());
    }

    private function createRequestWithSchemaAndHeaders(
        string $scheme = 'http',
        array $headers = []
    ): ServerRequestInterface {
        $request = new ServerRequest();

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $uri = $request->getUri()->withScheme($scheme);
        return $request->withUri($uri);
    }
}
