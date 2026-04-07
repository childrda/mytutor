<?php

return [

    'paths' => [
        resource_path('views'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Compiled View Path
    |--------------------------------------------------------------------------
    |
    | Blade compiles templates using an atomic write (tempnam + rename). If PHP
    | cannot create temp files in this directory, PHP emits a notice that Laravel
    | turns into ErrorException. When storage/ is not writable by the web server
    | user, set VIEW_COMPILED_PATH to a directory the server can write (e.g. under
    | /tmp) or fix ownership: chown -R www-data:www-data storage bootstrap/cache
    |
    */

    'compiled' => env(
        'VIEW_COMPILED_PATH',
        storage_path('framework/views')
    ),

];
