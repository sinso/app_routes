<?php

return [
    'frontend' => [
        'sinso/app-routes/route' => [
            'target' => \Sinso\AppRoutes\Middleware\AppRoutesMiddleware::class,
            'after' => [
                'typo3/cms-core/cache-tags-attribute',
                'typo3/cms-frontend/site',
                'typo3/cms-frontend/authentication',
            ],
            'before' => [
                'typo3/cms-frontend/base-redirect-resolver',
                'typo3/cms-frontend/prepare-tsfe-rendering',
            ],
        ],
    ],
];
