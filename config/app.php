<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'env' => Env::get('APP_ENV', 'production'),
    'debug' => Env::bool('APP_DEBUG', false),
    'name' => Env::get('APP_NAME', 'Assetverwaltung'),
    'company_name' => Env::get('APP_COMPANY_NAME', 'Firma'),
    'url' => rtrim((string) Env::get('APP_URL', 'http://localhost:8080'), '/'),
    'timezone' => Env::get('APP_TIMEZONE', 'Europe/Berlin'),
    'log_level' => Env::get('LOG_LEVEL', 'info'),
    'admin' => [
        'username' => Env::get('ADMIN_USERNAME', 'admin'),
        'password' => Env::get('ADMIN_PASSWORD', ''),
    ],
    'security' => [
        'csp' => "default-src 'self'; img-src 'self' data: blob:; style-src 'self'; script-src 'self'; media-src 'self' blob:; worker-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
        'session_secure' => Env::get('SESSION_SECURE') === null || Env::get('SESSION_SECURE') === '' ? null : Env::bool('SESSION_SECURE'),
        'session_same_site' => Env::get('SESSION_SAME_SITE', 'Lax'),
        'session_lifetime' => Env::int('SESSION_LIFETIME', 480),
        'login_max_attempts' => 10,
        'login_lockout_minutes' => 15,
    ],
];
