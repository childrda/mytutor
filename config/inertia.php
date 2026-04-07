<?php

/**
 * Inertia page resolution (must match resources/js/app.jsx: glob under ./Pages/ for .jsx files).
 * Default package config uses resource_path('js/pages'); this app uses capital Pages (case-sensitive on Linux).
 */
return [
    'pages' => [
        'ensure_pages_exist' => false,
        'paths' => [
            resource_path('js/Pages'),
        ],
        'extensions' => [
            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',
        ],
    ],
];
