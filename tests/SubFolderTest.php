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
        $request = $this->createRequest($uri = '/', $script = '/index.php');
        $mw = $this->createMiddleware(alias: '@baseUrl');

        $this->process($mw, $request);

        $this->assertSame('/default/web', $this->aliases->get('@baseUrl'));
        $this->assertSame('', $this->urlGeneratorUriPrefix);
        $this->assertSame($uri, $this->getRequestPath());
    }

    /**
     * @dataProvider dataProvider
     */
    public function testAutoPrefix(string $uri, string $script, string $expectedPrefix, $expectedPath): void
    {
        $request = $this->createRequest($uri, $script);
        $mw = $this->createMiddleware(alias: '@baseUrl');

        $this->process($mw, $request);

        $this->assertSame($expectedPrefix, $this->aliases->get('@baseUrl'));
        $this->assertSame($expectedPrefix, $this->urlGeneratorUriPrefix);
        $this->assertSame($expectedPath, $this->getRequestPath());
    }

    public function dataProvider(): array
    {
        return [
            'auto prefix' => [
                '/public/',
                '/public/index.php',
                '/public',
                '/',
            ],
            'auto prefix logn' => [
                '/root/php/dev-server/project-42/index_html/public/web/',
                '/root/php/dev-server/project-42/index_html/public/web/index.php',
                '/root/php/dev-server/project-42/index_html/public/web',
                '/',
            ],
            'auto prefix and uri without trailing slash' => [
                '/public',
                '/public/index.php',
                '/public',
                '/',
            ],
            'auto prefix full url' => [
                '/public/index.php?test',
                '/public/index.php',
                '/public',
                '/index.php',
            ],
            'failed auto prefix' => [
                '/web/index.php',
                '/public/index.php',
                '/public',
                '/web/index.php',
            ],
            'auto prefix does not match completely' => [
                '/public/web/',
                '/pub/index.php',
                '/pub',
                '/public/web/'
            ]
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
