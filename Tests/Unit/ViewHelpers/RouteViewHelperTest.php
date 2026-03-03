<?php

declare(strict_types=1);

namespace Sinso\AppRoutes\Tests\Unit\ViewHelpers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Sinso\AppRoutes\Service\Router;
use Sinso\AppRoutes\ViewHelpers\RouteViewHelper;
use Symfony\Component\Routing\Generator\UrlGenerator;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(RouteViewHelper::class)]
final class RouteViewHelperTest extends UnitTestCase
{
    #[Test]
    public function generatesUrlUsingRouter(): void
    {
        $urlGenerator = self::createStub(UrlGenerator::class);
        $urlGenerator->method('generate')->willReturn('https://example.com/api/order/42');

        $router = self::createStub(Router::class);
        $router->method('getUrlGenerator')->willReturn($urlGenerator);

        $subject = new RouteViewHelper($router);
        $subject->initializeArguments();
        $subject->setArguments([
            'routeName' => 'myApp.order',
            'parameters' => ['orderUid' => '42'],
        ]);

        $result = $subject->render();

        self::assertSame('https://example.com/api/order/42', $result);
    }

    #[Test]
    public function defaultsToEmptyParametersArray(): void
    {
        $urlGenerator = self::createStub(UrlGenerator::class);
        $urlGenerator->method('generate')->willReturn('https://example.com/api/list');

        $router = self::createStub(Router::class);
        $router->method('getUrlGenerator')->willReturn($urlGenerator);

        $subject = new RouteViewHelper($router);
        $subject->initializeArguments();
        $subject->setArguments([
            'routeName' => 'myApp.list',
            'parameters' => [],
        ]);

        $result = $subject->render();

        self::assertSame('https://example.com/api/list', $result);
    }
}
