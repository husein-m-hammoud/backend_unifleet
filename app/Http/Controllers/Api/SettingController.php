<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\SettingChangelog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    /**
     * GET /api/settings
     * Returns all settings grouped by their group key.
     */
    public function index(): JsonResponse
    {
        $settings = Setting::orderBy('group')->orderBy('key')->get();

        $grouped = $settings->groupBy('group')->map(function ($items, $group) {
            return [
                'group'    => $group,
                'settings' => $items->map(fn($s) => $this->format($s))->values(),
            ];
        })->values();

        return response()->json($grouped);
    }

    /**
     * PUT /api/settings/{key}
     * Update a single setting and record a changelog entry.
     */
    public function update(Request $request, string $key): JsonResponse
    {
        $setting = Setting::findOrFail($key);

        $request->validate([
            'value' => ['required', 'string', 'max:1000'],
        ]);

        $newRaw = $request->input('value');

        // Type-level validation
        match ($setting->type) {
            'integer' => $request->validate(['value' => 'integer']),
            'float'   => $request->validate(['value' => 'numeric']),
            'boolean' => $request->validate(['value' => 'in:true,false,1,0']),
            default   => null,
        };

        $oldRaw = $setting->value;

        if ($oldRaw === $newRaw) {
            return response()->json($this->format($setting));
        }

        $setting->update(['value' => $newRaw]);

        SettingChangelog::create([
            'setting_key'   => $key,
            'setting_label' => $setting->label,
            'old_value'     => $oldRaw,
            'new_value'     => $newRaw,
            'user_id'       => $request->user()->id,
            'ip_address'    => $request->ip(),
        ]);

        return response()->json($this->format($setting->fresh()));
    }

    /**
     * GET /api/settings/changelog
     * GET /api/settings/{key}/changelog
     * Returns recent changelog entries, optionally filtered by setting key.
     */
    public function changelog(Request $request, ?string $key = null): JsonResponse
    {
        $query = SettingChangelog::with('user:id,name')
            ->orderByDesc('changed_at')
            ->limit(200);

        if ($key) {
            $query->where('setting_key', $key);
        }

        return response()->json(
            $query->get()->map(fn($c) => [
                'id'            => $c->id,
                'setting_key'   => $c->setting_key,
                'setting_label' => $c->setting_label,
                'old_value'     => $c->old_value,
                'new_value'     => $c->new_value,
                'changed_at'    => $c->changed_at,
                'changed_by'    => $c->user?->name ?? 'System',
                'ip_address'    => $c->ip_address,
            ])
        );
    }

    private function format(Setting $s): array
    {
        return [
            'key'          => $s->key,
            'value'        => $s->value,
            'casted_value' => $s->casted_value,
            'type'         => $s->type,
            'label'        => $s->label,
            'description'  => $s->description,
            'group'        => $s->group,
        ];
    }
}
