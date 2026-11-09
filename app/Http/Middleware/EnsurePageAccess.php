<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Enforces a scoped user's page grant (`allowed_pages`) on the API. Unscoped
 * roles (owner/admin/support) see every page; a scoped `user` must have been
 * granted the page key in the Add/Edit User form. Usage: ->middleware('page:reports').
 *
 * NOTE: this gate covers the page-keyed endpoints only (fuel, ai, heatmap,
 * reports). The administrative pages - users, zones, contacts, settings - are
 * gated by the `manager` middleware instead, which is what keeps support out
 * of them even though support passes every check here.
 *
 * Only applied to endpoints that belong to a single page and aren't shared by
 * another page (e.g. the dashboard overview also reads alerts, so /alerts stays
 * open). Data is additionally zone-scoped via User::vehicleQuery().
 */
class EnsurePageAccess
{
    public function handle(Request $request, Closure $next, string $page)
    {
        $user = $request->user();

        if ($user?->seesWholeFleet() || in_array($page, $user?->allowed_pages ?? [], true)) {
            return $next($request);
        }

        throw new AccessDeniedHttpException("Access to the '{$page}' page is not granted.");
    }
}
