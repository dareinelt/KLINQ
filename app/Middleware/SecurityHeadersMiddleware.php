<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;

final class SecurityHeadersMiddleware
{
    public function __construct(private readonly Config $config) {}

    public function __invoke(Request $request, callable $next): Response
    {
        $response = $next($request);

        return $response
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'same-origin')
            ->withHeader('Permissions-Policy', 'camera=(self), microphone=(), geolocation=()')
            ->withHeader('Content-Security-Policy', (string) $this->config->get('app.security.csp'));
    }
}
