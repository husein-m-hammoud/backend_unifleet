<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Zone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Zone responsibles — people to notify about an issue in a zone. Admin/owner
 * only. A contact is assigned to specific zones or flagged "all zones" (which
 * also covers zones created later). See {@see Zone::responsibleContacts()}.
 */
class ContactController extends Controller
{
    /** GET /api/contacts — all contacts with their zone assignment. */
    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        $totalZones = Zone::count();

        $contacts = Contact::with(['zones:id,name', 'creator:id,name'])
            ->orderBy('name')
            ->get()
            ->map(fn (Contact $c) => $this->format($c, $totalZones));

        return response()->json($contacts);
    }

    /** POST /api/contacts — create a contact. Admin only. */
    public function store(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        $data = $this->validateContact($request);

        $contact = Contact::create([
            'name'        => trim($data['name']),
            'phone'       => trim($data['phone']),
            'email'       => $data['email'] ?? null,
            'description' => $data['description'] ?? null,
            'note'        => $data['note'] ?? null,
            'all_zones'   => $data['all_zones'] ?? false,
            'created_by'  => $request->user()->id,
        ]);

        // Explicit zone rows are only meaningful when not "all zones".
        $contact->zones()->sync(($contact->all_zones) ? [] : ($data['zone_ids'] ?? []));

        return response()->json(
            $this->format($contact->load(['zones:id,name', 'creator:id,name']), Zone::count()),
            201,
        );
    }

    /** PUT /api/contacts/{id} — update a contact and/or its zone assignment. Admin only. */
    public function update(Request $request, int $id): JsonResponse
    {
        $this->ensureAdmin($request);

        $contact = Contact::findOrFail($id);
        $data = $this->validateContact($request, updating: true);

        $contact->fill(array_filter([
            'name'  => isset($data['name']) ? trim($data['name']) : null,
            'phone' => isset($data['phone']) ? trim($data['phone']) : null,
        ], fn ($v) => $v !== null));

        // Nullable fields: apply when present (allow clearing to null/empty).
        foreach (['email', 'description', 'note'] as $f) {
            if (array_key_exists($f, $data)) {
                $contact->{$f} = $data[$f] !== '' ? $data[$f] : null;
            }
        }
        if (array_key_exists('all_zones', $data)) {
            $contact->all_zones = (bool) $data['all_zones'];
        }
        $contact->save();

        // Re-sync the pivot when either the flag or the explicit list was sent.
        if (array_key_exists('all_zones', $data) || array_key_exists('zone_ids', $data)) {
            $contact->zones()->sync($contact->all_zones ? [] : ($data['zone_ids'] ?? []));
        }

        return response()->json(
            $this->format($contact->load(['zones:id,name', 'creator:id,name']), Zone::count()),
        );
    }

    /** DELETE /api/contacts/{id} — remove a contact. Admin only. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->ensureAdmin($request);

        Contact::findOrFail($id)->delete(); // pivot rows cascade

        return response()->json(['deleted' => true]);
    }

    /** GET /api/zones/{id}/contacts — everyone responsible for a zone (incl. all-zones). */
    public function zoneContacts(Request $request, int $id): JsonResponse
    {
        $zone = Zone::findOrFail($id);

        $contacts = $zone->responsibleContacts()->get()
            ->map(fn (Contact $c) => [
                'id'          => $c->id,
                'name'        => $c->name,
                'phone'       => $c->phone,
                'email'       => $c->email,
                'all_zones'   => $c->all_zones,
                'description' => $c->description,
            ]);

        return response()->json($contacts);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function validateContact(Request $request, bool $updating = false): array
    {
        $req = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'name'        => [$req, 'string', 'max:255'],
            'phone'       => [$req, 'string', 'max:60'],
            'email'       => ['sometimes', 'nullable', 'email', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'note'        => ['sometimes', 'nullable', 'string', 'max:2000'],
            'all_zones'   => ['sometimes', 'boolean'],
            'zone_ids'    => ['sometimes', 'array'],
            'zone_ids.*'  => ['integer', 'exists:zones,id'],
        ]);
    }

    private function format(Contact $c, int $totalZones): array
    {
        return [
            'id'          => $c->id,
            'name'        => $c->name,
            'phone'       => $c->phone,
            'email'       => $c->email,
            'description' => $c->description,
            'note'        => $c->note,
            'all_zones'   => $c->all_zones,
            'zones'       => $c->all_zones
                ? []
                : $c->zones->map(fn (Zone $z) => ['id' => $z->id, 'name' => $z->name])->values(),
            // How many zones this contact currently covers (all when all_zones).
            'zone_count'  => $c->all_zones ? $totalZones : $c->zones->count(),
            'created_at'  => $c->created_at,
            'created_by'  => $c->creator?->name,
        ];
    }

    private function ensureAdmin(Request $request): void
    {
        if (! $request->user()?->is_admin) {
            throw new AccessDeniedHttpException('Admin access required.');
        }
    }
}
