<?php

declare(strict_types=1);
namespace Sinso\AppRoutes\ViewHelpers;

use Sinso\AppRoutes\Service\Router;
use Symfony\Component\Routing\Generator\UrlGenerator;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

class RouteViewHelper extends AbstractViewHelper
{
    public function __construct(private readonly Router $router) {}

    public function initializeArguments(): void
    {
        $this->registerArgument('routeName', 'string', '', true);
        $this->registerArgument('parameters', 'array', '', false, []);
    }

    public function render(): string
    {
        return $this->router->getUrlGenerator()->generate(
            $this->arguments['routeName'],
            $this->arguments['parameters'],
            UrlGenerator::ABSOLUTE_URL,
        );
    }
}
