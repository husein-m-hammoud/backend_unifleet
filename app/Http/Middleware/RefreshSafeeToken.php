<?php

namespace App\Http\Middleware;

use App\Services\SafeeUserAuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Silently refreshes the user's Safee access token if it is about to expire.
 * The frontend never sees the Safee token — it only uses our Sanctum token.
 */
class RefreshSafeeToken
{
    public function __construct(private SafeeUserAuthService $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            // Refreshes the Safee token internally if it expires within 60s;
            // the updated token is stored in the DB.
            $this->auth->getValidAccessToken($user);
        }

        return $next($request);
    }
}
