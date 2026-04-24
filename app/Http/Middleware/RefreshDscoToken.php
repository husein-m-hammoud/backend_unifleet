<?php

namespace App\Http\Middleware;

use App\Services\DscoUserAuthService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Silently refreshes the user's DSCO access token if it is about to expire.
 * The frontend never sees the DSCO token — it only uses our Sanctum token.
 */
class RefreshDscoToken
{
    public function __construct(private DscoUserAuthService $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            // This call refreshes the DSCO token internally if it expires within 60s.
            // We don't need the return value here — the updated token is stored in DB.
            $this->auth->getValidAccessToken($user);
        }

        return $next($request);
    }
}
