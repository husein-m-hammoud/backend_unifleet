<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Gates a route to managers (owner/admin). Mirrors UserController::ensureManager()
 * so manager-only pages (settings writes, contacts, users) are enforced on the API,
 * not just hidden in the React app.
 *
 * `manager:read` additionally admits a **support** account, but only for safe
 * HTTP methods — a helpdesk account may look at an administrative page and never
 * change it. That method check is what makes it safe to put `manager:read` on a
 * whole route group: the GETs open up while every POST/PUT/DELETE beside them
 * stays manager-only, so the group needs no splitting and a write route cannot
 * be loosened by accident later.
 */
class EnsureManager
{
    public function handle(Request $request, Closure $next, ?string $mode = null)
    {
        $user = $request->user();

        if ($user?->isManager()) {
            return $next($request);
        }

        if ($mode === 'read' && $user?->isSupport() && $request->isMethodSafe()) {
            return $next($request);
        }

        throw new AccessDeniedHttpException('Manager access required.');
    }
}
