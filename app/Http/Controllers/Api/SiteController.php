<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProviderSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteController extends Controller
{
    /**
     * GET /api/sites
     * Active sites that the user's own vehicles belong to — used as a map filter.
     * Scoped to the sites the user can actually see (not every site in the provider).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Provider + site IDs derived from the vehicles this user can see.
        $siteIds = $user->vehicleQuery()
            ->whereNotNull('dsco_site_id')
            ->distinct()
            ->pluck('dsco_site_id');

        $query = ProviderSite::active()
            ->whereIn('dsco_site_id', $siteIds)
            ->orderBy('name');

        if ($user->provider) {
            $query->where('provider', $user->provider);
        }

        $sites = $query->get()->map(fn ($s) => [
            'id'      => $s->id,
            'dsco_id' => $s->dsco_site_id,
            'name'    => $s->name,
        ]);

        return response()->json($sites);
    }
}
