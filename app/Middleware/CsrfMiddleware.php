<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Security\CsrfTokenManager;

/**
 * Prüft bei allen zustandsändernden Requests ein CSRF-Token
 * (Formularfeld "_csrf" oder Header "X-CSRF-Token").
 */
final class CsrfMiddleware
{
    public function __construct(private readonly CsrfTokenManager $csrf) {}

    public function __invoke(Request $request, callable $next): Response
    {
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $token = $request->header('X-CSRF-Token') ?? (string) $request->input('_csrf', '');
            if (!$this->csrf->validate($token)) {
                if ($request->wantsJson()) {
                    return Response::json(['error' => 'CSRF-Token ungültig oder Sitzung abgelaufen.', 'code' => 'csrf'], 419);
                }

                return Response::html('<h1>419 – Sitzung abgelaufen</h1><p>Bitte Seite neu laden und erneut versuchen.</p>', 419);
            }
        }

        return $next($request);
    }
}
