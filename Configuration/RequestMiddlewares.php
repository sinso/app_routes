<?php

use Sinso\AppRoutes\Middleware\AppRoutesMiddleware;

return [
    'frontend' => [
        'sinso/app-routes/route' => [
            'target' => AppRoutesMiddleware::class,
            'after' => [
                'typo3/cms-frontend/site',
                'typo3/cms-frontend/authentication',
            ],
            'before' => [
                'typo3/cms-frontend/base-redirect-resolver',
            ],
        ],
    ],
];
