<?php

declare(strict_types=1);

/**
 * Lumora Press configuration.
 *
 * This file is generated automatically by the installer at
 * config/config.php. This example is kept for reference only — do not
 * edit config/config.php by hand unless you know what you are doing.
 */
return [
    'db_host' => '127.0.0.1',
    'db_port' => 3306,
    'db_name' => 'lumorapress',
    'db_user' => 'lumorapress',
    'db_password' => '',
    'db_charset' => 'utf8mb4',
    // The installer generates a random prefix like "lum_a8f3d1_" rather
    // than defaulting to a predictable value such as "lp_".
    'table_prefix' => 'lum_a8f3d1_',

    'secret_key' => '',

    'debug' => false,

    // Sends a strict, same-origin-only Content-Security-Policy header on
    // every response. Set to false only if something else in front of
    // Lumora Press (a reverse proxy, etc.) already sets its own CSP.
    'csp_enabled' => true,

    'timezone' => 'UTC',
    'locale' => 'en',
];
