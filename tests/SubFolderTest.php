<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Middleware\Tests\Middleware;

use HttpSoft\Message\Response;
use HttpSoft\Message\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Aliases\Aliases;
use Yiisoft\Http\Method;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\Middleware\Exception\BadUriPrefixException;
use Yiisoft\Yii\Middleware\SubFolder;

final class SubFolderTest extends TestCase
{
    private string $urlGeneratorUriPrefix;
    private Aliases $aliases;
    private ?ServerRequestInterface $lastRequest;

    public function setUp(): void
    {
        $this->urlGeneratorUriPrefix = '';
        $this->lastRequest = null;
        $this->aliases = new Aliases(['@baseUrl' => '/default/web']);
    }

    public function testDefault(): void
    {
        $request = $this->createRequest($uri = '/', '/index.php');
        $mw = $this->createMiddleware(alias: '@baseUrl');

        $this->process($mw, $request);

        $this->assertSame('/default/web', $this->aliases->get('@baseUrl'));
        $this->assertSame('', $this->urlGeneratorUriPrefix);
        $this->assertSame($uri, $this->getRequestPath());
    }

    public function testCustomPrefix(): void
    {
        $request = $this->createRequest('/custom_public/index.php?test', '/index.php');
        $mw = $this->createMiddleware(prefix: '/custom_public', alias: '@baseUrl');

        $this->process($mw, $request);

        $this->assertSame('/default/web', $this->aliases->get('@baseUrl'));
        $this->assertSame('/custom_public', $this->urlGeneratorUriPrefix);
        $this->assertSame('/index.php', $this->getRequestPath());
    }

    public function testCustomPrefixWithTrailingSlash(): void
    {
        $request = $this->createRequest('/web/', '/public/index.php');
        $mw = $this->createMiddleware('/web/', '@baseUrl');

        $this->expectException(BadUriPrefixException::class);
        $this->expectExceptionMessage('Wrong URI prefix value');

        $this->process($mw, $request);
    }

    public function testCustomPrefixFromMiddleOfUri(): void
    {
        $request = $this->createRequest('/web/middle/public', '/public/index.php');
        $mw = $this->createMiddleware('/middle', '@baseUrl');

        $this->expectException(BadUriPrefixException::class);
        $this->expectExceptionMessage('URI prefix does not match');

        $this->process($mw, $request);
    }

    public function testCustomPrefixDoesNotMatch(): void
    {
        $request = $this->createRequest('/web/', '/public/index.php');
        $mw = $this->createMiddleware('/other_prefix', '@baseUrl');

        $this->expectException(BadUriPrefixException::class);
        $this->expectExceptionMessage('URI prefix does not match');

        $this->process($mw, $request);
    }

    public function testCustomPrefixDoesNotMatchCompletely(): void
    {
        $request = $this->createRequest('/project1/web/', '/public/index.php');
        $mw = $this->createMiddleware('/project1/we', '@baseUrl');

        $this->expectException(BadUriPrefixException::class);
        $this->expectExceptionMessage('URI prefix does not match completely');

        $this->process($mw, $request);
    }

    /**
     * @dataProvider autoPrefixDataProvider
     */
    public function testAutoPrefix(
        string $uri,
        string $script,
        string $expectedBaseUrl,
        string $expectedPrefix,
        $expectedPath
    ): void {
        $request = $this->createRequest($uri, $script);
        $mw = $this->createMiddleware(alias: '@baseUrl');

        $this->process($mw, $request);

        $this->assertSame($expectedBaseUrl, $this->aliases->get('@baseUrl'));
        $this->assertSame($expectedPrefix, $this->urlGeneratorUriPrefix);
        $this->assertSame($expectedPath, $this->getRequestPath());
    }

    /**
     * @dataProvider scriptParamsProvider
     */
    public function testAutoPrefixWithScriptParams(array $scriptParams): void
    {
        $request = new ServerRequest(serverParams: $scriptParams, uri: '/public/');
        $mw = $this->createMiddleware(alias: '@baseUrl');

        $this->process($mw, $request);

        $this->assertSame('/public', $this->aliases->get('@baseUrl'));
        $this->assertSame('/public', $this->urlGeneratorUriPrefix);
        $this->assertSame('/', $this->getRequestPath());
    }

    public function autoPrefixDataProvider(): array
    {
        return [
            'auto prefix' => [
                '/public/',
                '/public/index.php',
                '/public',
                '/public',
                '/',
            ],
            'auto prefix logn' => [
                '/root/php/dev-server/project-42/index_html/public/web/',
                '/root/php/dev-server/project-42/index_html/public/web/index.php',
                '/root/php/dev-server/project-42/index_html/public/web',
                '/root/php/dev-server/project-42/index_html/public/web',
                '/',
            ],
            'auto prefix and uri without trailing slash' => [
                '/public',
                '/public/index.php',
                '/public',
                '/public',
                '/',
            ],
            'auto prefix full url' => [
                '/public/index.php?test',
                '/public/index.php',
                '/public',
                '/public',
                '/index.php',
            ],
            'failed auto prefix' => [
                '/web/index.php',
                '/public/index.php',
                '/default/web',
                '',
                '/web/index.php',
            ],
            'auto prefix does not match completely' => [
                '/public/web/',
                '/pub/index.php',
                '/default/web',
                '',
                '/public/web/',
            ],
        ];
    }

    public function scriptParamsProvider(): iterable
    {
        yield [
            [
                'PHP_SELF' => '/public/index.php',
                'SCRIPT_FILENAME' => '/public/index.php',
            ],
        ];
        yield [
            [
                'PHP_SELF' => '/public/index.php/test',
                'SCRIPT_FILENAME' => '/public/index.php',
            ],
        ];
        yield [
            [
                'ORIG_SCRIPT_NAME' => '/public/index.php',
                'SCRIPT_FILENAME' => '/public/index.php',
            ],
        ];
        yield [
            [
                'DOCUMENT_ROOT' => '/www',
                'SCRIPT_FILENAME' => '/www/public/index.php',
            ],
        ];
    }

    private function process(SubFolder $middleware, ServerRequestInterface $request): ResponseInterface
    {
        $handler = new class () implements RequestHandlerInterface {
            public ?ServerRequestInterface $request = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->request = $request;
                return new Response();
            }
        };

        $this->lastRequest = &$handler->request;
        return $middleware->process($request, $handler);
    }

    private function getRequestPath(): string
    {
        return $this->lastRequest
            ->getUri()
            ->getPath();
    }

    private function createMiddleware(?string $prefix = null, ?string $alias = null): SubFolder
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator
            ->method('setUriPrefix')
            ->willReturnCallback(function ($prefix) {
                $this->urlGeneratorUriPrefix = $prefix;
            });

        $urlGenerator
            ->method('getUriPrefix')
            ->willReturnReference($this->urlGeneratorUriPrefix);
        return new SubFolder($urlGenerator, $this->aliases, prefix: $prefix, baseUrlAlias: $alias);
    }

    private function createRequest(string $uri = '/', string $scriptPath = '/'): ServerRequestInterface
    {
        return new ServerRequest(['SCRIPT_FILENAME' => $scriptPath], [], [], [], null, Method::GET, $uri);
    }
}
