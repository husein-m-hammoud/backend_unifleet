<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Gates a route to OWNER accounts only — stricter than `manager`, which also
 * lets admins through.
 *
 * Used for the diagnostics endpoints. Those expose log contents, config state
 * and provider failure detail, so "the page has no nav link" is not the control
 * here — this middleware is. Hiding the link is only so the client's admins
 * don't wander into it.
 */
class EnsureOwner
{
    public function handle(Request $request, Closure $next, ?string $mode = null)
    {
        $user = $request->user();

        if ($user?->isOwner()) {
            return $next($request);
        }

        // `owner:read` also admits support, safe methods only — so a helpdesk
        // account can read the diagnostics report, the logs and the provider
        // failure history, but cannot fire `POST test-alert`.
        if ($mode === 'read' && $user?->isSupport() && $request->isMethodSafe()) {
            return $next($request);
        }

        throw new AccessDeniedHttpException('Owner access required.');
    }
}
