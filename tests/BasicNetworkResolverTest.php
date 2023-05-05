<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Middleware\Tests;

use HttpSoft\Message\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use stdClass;
use Yiisoft\Yii\Middleware\BasicNetworkResolver;
use Yiisoft\Yii\Middleware\Tests\TestAsset\MockRequestHandler;

final class BasicNetworkResolverTest extends TestCase
{
    public function testImmutability(): void
    {
        $middleware = new BasicNetworkResolver();
        $this->assertNotSame($middleware, $middleware->withAddedProtocolHeader('test'));

        $middleware = new BasicNetworkResolver();
        $this->assertNotSame($middleware, $middleware->withoutProtocolHeader('test'));

        $middleware = new BasicNetworkResolver();
        $this->assertNotSame($middleware, $middleware->withoutProtocolHeaders(['test1', 'test2']));

        $middleware = new BasicNetworkResolver();
        $this->assertNotSame($middleware, $middleware->withoutProtocolHeaders([]));
    }

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
            'protocol header written in upper case' => [
                'http',
                ['x-forwarded-proto' => ['https']],
                [
                    'x-forwarded-proto' => ['https' => 'https'],
                    'X-FORWARDED-PROTO' => ['http' => 'http'],
                ],
                'http',
            ],
            'request header value written in upper case' => [
                'http',
                ['x-forwarded-proto' => ['HTTPS']],
                ['x-forwarded-proto' => ['https' => 'https']],
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
            'multiple protocol headers' => [
                'http',
                ['x-forwarded-proto-2' => ['https']],
                [
                    'x-forwarded-proto-1' => ['http' => 'http'],
                    'x-forwarded-proto-2' => ['https' => 'https'],
                    'x-forwarded-proto-3' => ['http' => 'http'],
                ],
                'https',
            ],
            'multiple request and protocol headers, callback returning null' => [
                'http',
                [
                    'x-forwarded-proto-2' => ['http'],
                    'x-forwarded-proto-3' => ['https'],
                ],
                [
                    'x-forwarded-proto-1' => ['http' => 'http'],
                    'x-forwarded-proto-2' => static fn () => null,
                    'x-forwarded-proto-3' => ['https' => 'https'],
                    'x-forwarded-proto-4' => ['http' => 'http'],
                ],
                'https',
            ],
            'multiple request and protocol headers, callback returning scheme' => [
                'http',
                [
                    'x-forwarded-proto-2' => ['https'],
                    'x-forwarded-proto-3' => ['http'],
                ],
                [
                    'x-forwarded-proto-1' => ['http' => 'http'],
                    'x-forwarded-proto-2' => static fn(array $values): string => str_contains($values[0], 'https') ? 'https' : 'http',
                    'x-forwarded-proto-3' => ['http' => 'http'],
                ],
                'https',
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

    public function schemeArrayFailureDataProvider(): array
    {
        return [
            'int-key' => [['https'], 'The protocol must be type of string.'],
            'empty-value' => [[], 'Protocol header values cannot be an empty array.'],
            'empty-array' => [['http' => []], 'Protocol accepted values cannot be an empty array.'],
            'empty-string-key' => [['' => 'http'], 'The protocol cannot be an empty string.'],
        ];
    }

    /**
     * @dataProvider schemeArrayFailureDataProvider
     */
    public function testArraySchemeFailure(array $schemeValues, string $errorMessage): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($errorMessage);

        (new BasicNetworkResolver())->withAddedProtocolHeader('x-forwarded-proto', $schemeValues);
    }

    public function schemeCallableFailureDataProvider(): array
    {
        return [
            'int' => [1],
            'float' => [1.1],
            'true' => [true],
            'false' => [false],
            'array' => [['https']],
            'empty-array' => [[]],
            'empty-string' => [''],
            'object' => [new StdClass()],
            'callable' => [static fn () => 'https'],
        ];
    }

    /**
     * @dataProvider schemeCallableFailureDataProvider
     */
    public function testCallableSchemeFailure(mixed $scheme): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request
            ->method('hasHeader')
            ->willReturn(true);
        $request
            ->method('getHeader')
            ->willReturn($scheme);

        $middleware = (new BasicNetworkResolver())
            ->withAddedProtocolHeader('x-forwarded-proto', static fn () => $scheme)
        ;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            $scheme === '' ? 'The scheme cannot be an empty string.' : 'The scheme is neither string nor null.',
        );

        $middleware->process($request, new MockRequestHandler());
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
        $this->assertSame('http', $resultRequest
            ->getUri()
            ->getScheme());
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
            ->withoutProtocolHeaders(['x-forwarded-proto', 'X-FORWARDED-PROTO-2'])
        ;

        $middleware->process($request, $requestHandler);
        $resultRequest = $requestHandler->processedRequest;

        /* @var $resultRequest ServerRequestInterface */
        $this->assertSame('http', $resultRequest
            ->getUri()
            ->getScheme());
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
            ->withoutProtocolHeader('x-forwarded-proto')
        ;

        $middleware->process($request, $requestHandler);
        $resultRequest = $requestHandler->processedRequest;

        /* @var $resultRequest ServerRequestInterface */
        $this->assertSame('http', $resultRequest
            ->getUri()
            ->getScheme());

        $middleware = $middleware->withoutProtocolHeader('X-FORWARDED-PROTO-2');
        $middleware->process($request, $requestHandler);
        $resultRequest = $requestHandler->processedRequest;

        /* @var $resultRequest ServerRequestInterface */
        $this->assertSame('https', $resultRequest
            ->getUri()
            ->getScheme());
    }

    private function createRequestWithSchemaAndHeaders(
        string $scheme = 'http',
        array $headers = [],
    ): ServerRequestInterface {
        $request = new ServerRequest();

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $uri = $request
            ->getUri()
            ->withScheme($scheme);
        return $request->withUri($uri);
    }
}
