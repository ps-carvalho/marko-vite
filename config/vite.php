<?php

declare(strict_types=1);

return [
    'buildDirectory' => 'build',
    'manifestFilename' => '.vite/manifest.json',
    'devServerUrl' => env('VITE_DEV_SERVER_URL', 'http://localhost:5173'),
    'devServerStylesheets' => [
        'app/web/resources/css/app.css',
    ],
    'useDevServer' => env('VITE_USE_DEV_SERVER', env('APP_ENV', 'local') === 'local'),
];
