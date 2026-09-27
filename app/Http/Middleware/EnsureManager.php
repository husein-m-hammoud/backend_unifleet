<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Gates a route to managers (owner/admin) only. Mirrors UserController::ensureManager()
 * so manager-only pages (settings writes, contacts, users) are enforced on the API,
 * not just hidden in the React app.
 */
class EnsureManager
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user()?->isManager()) {
            throw new AccessDeniedHttpException('Manager access required.');
        }

        return $next($request);
    }
}
