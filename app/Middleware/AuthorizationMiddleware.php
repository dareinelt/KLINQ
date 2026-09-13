<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Exceptions\ForbiddenException;
use App\Security\CurrentUser;

/**
 * Serverseitige Zugriffskontrolle je Route:
 *  - public=true          → frei zugänglich (Login, Manifest, Service Worker …)
 *  - permission=null      → angemeldeter Benutzer erforderlich
 *  - permission='x.y'     → Recht erforderlich
 */
final class AuthorizationMiddleware
{
    public function __construct(private readonly Router $router, private readonly CurrentUser $currentUser) {}

    public function __invoke(Request $request, callable $next): Response
    {
        $route = $this->router->matchedRoute();
        if ($route === null || $route->public) {
            return $next($request);
        }

        if (!$this->currentUser->isAuthenticated()) {
            if ($request->wantsJson()) {
                return Response::json(['error' => 'Nicht angemeldet.', 'code' => 'unauthenticated'], 401);
            }
            $target = $request->method() === 'GET' ? '?redirect=' . rawurlencode($request->fullUrl()) : '';

            return Response::redirect('/login' . $target);
        }

        if ($route->permission !== null && !$this->currentUser->can($route->permission)) {
            throw new ForbiddenException();
        }

        return $next($request);
    }
}
