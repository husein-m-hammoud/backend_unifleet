<?php

namespace App\Console\Commands;

use App\Services\SafeeAuthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Read-only API conformance probe for one Safee provider.
 *
 *   php artisan safee:probe alrakeen
 *   php artisan safee:probe dsco
 *
 * WHY THIS EXISTS: not every Safee deployment speaks the same dialect. The
 * request shapes in SafeeApiService were corrected against v2.2.0.0 on Alrakeen
 * (fuel/speed/weight take {id}; trips take {pageSize,pageIndex,filter}), but the
 * original DSCO integration used the OLDER shape ({vehicleId,startDate,endDate};
 * trip path at api/v2/trip/path). An older deployment therefore fails SOFTLY —
 * vehicles and positions sync fine while fuel, speed, weight and trips quietly
 * return nothing, which looks like "no data" rather than an error and does not
 * trip the provider health check.
 *
 * This probe sends both dialects to each endpoint and reports which one the
 * provider accepts, so a newly onboarded provider can be verified in one run.
 * It only ever READS — nothing here writes to the database.
 */
class SafeeProbeCommand extends Command
{
    protected $signature = 'safee:probe {provider? : Provider key (default: config default)}';

    protected $description = 'Probe a Safee provider API for endpoint + request-shape (dialect) compatibility';

    public function handle(): int
    {
        $provider = $this->argument('provider') ?? config('services.safee.default');

        try {
            $auth = new SafeeAuthService($provider);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->line("Probing <info>{$provider}</info> at {$auth->baseUrl()}");

        try {
            $token = $auth->getToken();
        } catch (\Throwable $e) {
            $this->error('AUTH FAILED — ' . $this->firstLine($e->getMessage()));
            $this->line('  Everything below needs a token. Fix the service-account credentials first.');
            return self::FAILURE;
        }

        $this->info('AUTH OK (' . strlen($token) . '-char token)');
        $this->newLine();

        // --- Step 1: unparameterised list endpoints ------------------------
        $this->line('<comment>Reference endpoints</comment>');
        $lists = [
            'api/v2/status'            => 'result',   // GET, see $getEndpoints

            'api/v2/site/list'         => 'result',
            'api/v2/category/list'     => 'result',
            'api/v2/geofence/list'     => 'result',
            'api/v2/vehicle/list-info' => 'result',
            'api/v2/driver/list-info'  => 'drivers',
        ];
        $getEndpoints = ['api/v2/status'];
        $vehicleId = null;
        foreach ($lists as $endpoint => $key) {
            $method = in_array($endpoint, $getEndpoints, true) ? 'get' : 'post';
            [$ok, $note, $payload] = $this->probe($token, $auth->baseUrl(), $endpoint, [], $key, $method);
            $this->row($endpoint, $ok, $note);

            if ($ok && $endpoint === 'api/v2/vehicle/list-info' && is_array($payload)) {
                $first = $payload[0] ?? null;
                $vehicleId = is_array($first) ? ($first['id'] ?? $first['vehicleId'] ?? null) : null;
            }
        }

        if (! $vehicleId) {
            $this->newLine();
            $this->warn('No vehicle id available — cannot probe per-vehicle dialects.');
            return self::SUCCESS;
        }

        // --- Step 2: dialect-sensitive per-vehicle endpoints ---------------
        $this->newLine();
        $this->line("<comment>Per-vehicle endpoints (dialect test, vehicle id {$vehicleId})</comment>");

        $now   = round(microtime(true), 3);
        $start = $now - (24 * 3600);

        $cases = [
            'vehicle/get-fuel-data'   => [
                'new' => ['id' => $vehicleId],
                'old' => ['vehicleId' => $vehicleId, 'startDate' => $start, 'endDate' => $now],
            ],
            'vehicle/get-speed-data'  => [
                'new' => ['id' => $vehicleId, 'numberOfHours' => 24],
                'old' => ['vehicleId' => $vehicleId, 'startDate' => $start, 'endDate' => $now],
            ],
            'vehicle/get-weight-data' => [
                'new' => ['id' => $vehicleId],
                'old' => ['vehicleId' => $vehicleId, 'startDate' => $start, 'endDate' => $now],
            ],
            'vehicle/trips'           => [
                'new' => ['pageSize' => 5, 'pageIndex' => 0, 'filter' => ['vehicleId' => $vehicleId, 'startDate' => $start, 'endDate' => $now]],
                'old' => ['vehicleId' => $vehicleId, 'startDate' => $start, 'endDate' => $now],
            ],
        ];

        $verdict = [];
        foreach ($cases as $path => $shapes) {
            foreach ($shapes as $dialect => $body) {
                [$ok, $note] = $this->probe($token, $auth->baseUrl(), "api/v2/{$path}", $body, 'result');
                $this->row("  {$path} [{$dialect}]", $ok, $note);
                if ($ok) {
                    $verdict[$path] = $verdict[$path] ?? $dialect;
                }
            }
        }

        // Trip path moved between versions: api/v2/trip/path -> api/v2/vehicle/trip/path
        foreach (['api/v2/vehicle/trip/path' => 'new', 'api/v2/trip/path' => 'old'] as $path => $dialect) {
            [$ok, $note] = $this->probe($token, $auth->baseUrl(), $path, ['id' => 1, 'tripId' => 1], 'result');
            $this->row("  {$path} [{$dialect}]", $ok, $note);
        }

        // --- Verdict --------------------------------------------------------
        $this->newLine();
        $accepted = array_unique(array_values($verdict));
        if (empty($verdict)) {
            $this->error('VERDICT: no per-vehicle endpoint accepted either dialect — inspect the notes above.');
        } elseif ($accepted === ['new']) {
            $this->info('VERDICT: speaks the CURRENT dialect (v2.2.0.0) — SafeeApiService works as-is.');
        } elseif ($accepted === ['old']) {
            $this->error('VERDICT: speaks the OLD dialect — SafeeApiService needs a per-provider shape switch,');
            $this->error('         otherwise fuel/speed/weight/trips will silently return nothing.');
        } else {
            $this->warn('VERDICT: MIXED dialect — per-endpoint shapes needed:');
            foreach ($verdict as $path => $dialect) {
                $this->warn("         {$path} => {$dialect}");
            }
        }

        return self::SUCCESS;
    }

    /** @return array{0:bool,1:string,2:mixed} [accepted, note, payload] */
    private function probe(string $token, string $baseUrl, string $endpoint, array $body, string $resultKey, string $method = 'post'): array
    {
        try {
            $http = Http::asJson()
                ->withToken($token)
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(30);

            // Same quirk SafeeApiService handles: an empty PHP [] encodes to
            // "[]" but Safee's JSON parser demands "{}".
            $response = $method === 'get'
                ? $http->get("{$baseUrl}/{$endpoint}", $body)
                : $http->post("{$baseUrl}/{$endpoint}", empty($body) ? new \stdClass() : $body);
        } catch (\Throwable $e) {
            return [false, 'transport: ' . $this->firstLine($e->getMessage()), null];
        }

        if ($response->status() !== 200) {
            return [false, 'HTTP ' . $response->status() . ' ' . $this->firstLine((string) $response->body()), null];
        }

        // A Safee 200 can still carry a logical failure in the envelope.
        $envelopeStatus = $response->json('status');
        $message        = (string) ($response->json('message') ?? '');
        if (is_string($envelopeStatus) && strtoupper($envelopeStatus) === 'FAILED') {
            return [false, 'envelope FAILED: ' . $this->firstLine($message), null];
        }

        $payload = $response->json($resultKey);
        $shape   = is_array($payload)
            ? (array_is_list($payload) ? count($payload) . ' rows' : 'object')
            : ($payload === null ? 'null' : gettype($payload));

        return [true, $shape, $payload];
    }

    private function row(string $label, bool $ok, string $note): void
    {
        $this->line(sprintf('  %s %s  %s', $ok ? '<info>✓</info>' : '<fg=red>✗</>', str_pad($label, 34), $note));
    }

    private function firstLine(string $s): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s));
        return strlen($s) > 120 ? substr($s, 0, 120) . '…' : $s;
    }
}
