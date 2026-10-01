<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProviderApiFailure;
use App\Services\OpsNotifier;
use App\Services\SystemHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

/**
 * Diagnostics API.
 *
 *   GET /api/health                       — PUBLIC, terse. For an external uptime monitor.
 *   GET /api/admin/diagnostics            — owner only. Full report for the System page.
 *   GET /api/admin/diagnostics/logs       — owner only. Log file list + tail.
 *   GET /api/admin/diagnostics/failures   — owner only. Safee failure history ("what failed, when").
 *   POST /api/admin/diagnostics/test-alert— owner only. Proves ops delivery works.
 *
 * Everything under /admin is gated by the `owner` middleware in routes/api.php.
 * The public endpoint deliberately returns no detail — see publicSummary().
 */
class DiagnosticsController extends Controller
{
    /**
     * Unauthenticated health endpoint for uptime monitoring.
     *
     * Terse by default so it cannot be used to fingerprint the deployment.
     * Pass the OPS_HEALTH_TOKEN (as ?token= or the X-Health-Token header) to get
     * the full report instead — useful for a monitor that can send a header.
     *
     * Returns HTTP 200 when healthy or merely warning, 503 when a check fails,
     * which is what uptime monitors alert on.
     */
    public function health(Request $request, SystemHealthService $health): JsonResponse
    {
        $token    = config('services.ops.health_token');
        $provided = $request->header('X-Health-Token') ?? $request->query('token');

        $full = $token && is_string($provided) && hash_equals($token, $provided);

        $payload = $full ? $health->report() : $health->publicSummary();

        return response()->json(
            $payload,
            $payload['status'] === SystemHealthService::FAIL ? 503 : 200,
        );
    }

    /** Full report — the System page's main payload. */
    public function index(SystemHealthService $health): JsonResponse
    {
        return response()->json($health->report());
    }

    /**
     * Log file listing, or the tail of one file.
     *
     * Reading files from an HTTP request is the risky part of this feature, so
     * the path is never taken from the client directly: the caller passes a bare
     * filename, it must match the log-name pattern, and the resolved real path
     * must still sit inside storage/logs. That blocks `../` traversal and
     * symlinks pointing elsewhere.
     */
    public function logs(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file'  => ['nullable', 'string', 'max:120'],
            'lines' => ['nullable', 'integer', 'min:10', 'max:2000'],
            'level' => ['nullable', 'string', 'in:all,debug,info,notice,warning,error,critical,alert,emergency'],
            'q'     => ['nullable', 'string', 'max:200'],
        ]);

        $dir = storage_path('logs');

        $files = collect(File::exists($dir) ? File::files($dir) : [])
            ->filter(fn ($f) => str_ends_with($f->getFilename(), '.log'))
            ->map(fn ($f) => [
                'name'          => $f->getFilename(),
                'bytes'         => $f->getSize(),
                'modified_at'   => date(DATE_ATOM, $f->getMTime()),
            ])
            ->sortByDesc('modified_at')
            ->values();

        $requested = $data['file'] ?? $files->first()['name'] ?? null;

        if ($requested === null) {
            return response()->json(['files' => $files, 'file' => null, 'entries' => []]);
        }

        $path = $this->safeLogPath($requested);

        if ($path === null) {
            return response()->json(['message' => 'Unknown log file.'], 404);
        }

        $entries = $this->parseEntries(
            $this->tail($path, (int) ($data['lines'] ?? 300)),
            $data['level'] ?? 'all',
            $data['q'] ?? null,
        );

        return response()->json([
            'files'   => $files,
            'file'    => $requested,
            'bytes'   => filesize($path) ?: 0,
            'entries' => $entries,
        ]);
    }

    /**
     * Safee failure history — the direct answer to "did Safee fail, and when?".
     */
    public function failures(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['nullable', 'string', 'max:60'],
            'hours'    => ['nullable', 'integer', 'min:1', 'max:8760'],
            'limit'    => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $hours = (int) ($data['hours'] ?? 168);   // a week by default

        $query = ProviderApiFailure::where('occurred_at', '>=', now()->subHours($hours))
            ->when($data['provider'] ?? null, fn ($q, $p) => $q->where('provider', $p))
            ->latest('occurred_at');

        $rows = $query->limit((int) ($data['limit'] ?? 200))->get()->map(fn ($f) => [
            'id'          => $f->id,
            'provider'    => $f->provider,
            'kind'        => $f->kind,
            'endpoint'    => $f->endpoint,
            'status_code' => $f->status_code,
            'message'     => $f->message,
            'occurred_at' => $f->occurred_at?->toIso8601String(),
        ]);

        // An hour-by-hour count makes a sustained outage visible at a glance,
        // rather than having to read 200 individual rows.
        $buckets = ProviderApiFailure::where('occurred_at', '>=', now()->subHours($hours))
            ->when($data['provider'] ?? null, fn ($q, $p) => $q->where('provider', $p))
            ->selectRaw("date_trunc('hour', occurred_at) as hour, provider, count(*) as c")
            ->groupBy('hour', 'provider')
            ->orderBy('hour')
            ->get()
            ->map(fn ($r) => [
                'hour'     => \Illuminate\Support\Carbon::parse($r->hour)->toIso8601String(),
                'provider' => $r->provider,
                'count'    => (int) $r->c,
            ]);

        return response()->json([
            'window_hours' => $hours,
            'total'        => $query->count(),
            'failures'     => $rows,
            'hourly'       => $buckets,
            'last_success' => collect(array_keys(config('services.safee.providers', [])))
                ->mapWithKeys(fn ($p) => [$p => ProviderApiFailure::lastSuccessAt($p)]),
        ]);
    }

    /**
     * Send a test ops notification, so "will I actually be told?" is verifiable
     * rather than assumed. Bypasses the dedupe cooldown.
     */
    public function testAlert(): JsonResponse
    {
        $channels = OpsNotifier::send(
            'Test notification',
            "This is a test from the UNIFLEET diagnostics page.\nIf you are reading this, ops alerting works.",
        );

        return response()->json([
            'configured' => OpsNotifier::configuredChannels(),
            'delivered'  => $channels,
            'message'    => $channels === []
                ? 'No channel is configured — set OPS_ALERT_EMAIL and a real MAIL_MAILER (not `log`).'
                : 'Sent via ' . implode(' + ', $channels) . '.',
        ], $channels === [] ? 422 : 200);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve a client-supplied log filename to a real path inside
     * storage/logs, or null. Defence in depth: pattern check, then a realpath
     * containment check.
     */
    private function safeLogPath(string $name): ?string
    {
        if (! preg_match('/^[A-Za-z0-9._-]+\.log$/', $name)) {
            return null;
        }

        $dir  = realpath(storage_path('logs'));
        $path = realpath(storage_path('logs/' . $name));

        if ($dir === false || $path === false) {
            return null;
        }

        // The resolved file must still live under storage/logs.
        return str_starts_with($path, $dir . DIRECTORY_SEPARATOR) ? $path : null;
    }

    /**
     * Read the last $lines lines without loading the whole file — laravel.log is
     * already ~8 MB and a stack trace can be hundreds of lines.
     */
    private function tail(string $path, int $lines): array
    {
        $handle = @fopen($path, 'rb');

        if (! $handle) {
            return [];
        }

        try {
            // Walk backwards in chunks until enough newlines have been seen or
            // the start of the file is reached.
            $buffer = '';
            $chunk  = 8192;
            $pos    = filesize($path) ?: 0;
            $found  = 0;

            while ($pos > 0 && $found <= $lines) {
                $read = (int) min($chunk, $pos);
                $pos -= $read;
                fseek($handle, $pos);
                $data   = (string) fread($handle, $read);
                $buffer = $data . $buffer;
                $found  = substr_count($buffer, "\n");

                // Hard ceiling so a pathological single-line file cannot exhaust
                // memory here.
                if (strlen($buffer) > 4 * 1024 * 1024) {
                    break;
                }
            }

            $all = preg_split('/\r?\n/', $buffer) ?: [];

            return array_slice($all, -$lines);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Group raw log lines into entries. A Laravel entry starts with
     * `[2026-09-24T01:11:22...] env.LEVEL: message`; everything after that until
     * the next such line (stack trace, context) belongs to it.
     */
    private function parseEntries(array $lines, string $level, ?string $search): array
    {
        $entries = [];
        $current = null;

        foreach ($lines as $line) {
            if (preg_match('/^\[(.+?)\]\s+(\S+?)\.(\w+):\s?(.*)$/', $line, $m)) {
                if ($current !== null) {
                    $entries[] = $current;
                }

                $current = [
                    'at'      => $m[1],
                    'channel' => $m[2],
                    'level'   => strtolower($m[3]),
                    'message' => $m[4],
                    'context' => '',
                ];

                continue;
            }

            if ($current !== null && trim($line) !== '') {
                // Keep the trace bounded; the full thing is on disk if needed.
                if (strlen($current['context']) < 4000) {
                    $current['context'] .= ($current['context'] === '' ? '' : "\n") . $line;
                }
            }
        }

        if ($current !== null) {
            $entries[] = $current;
        }

        if ($level !== 'all') {
            $entries = array_filter($entries, fn ($e) => $e['level'] === $level);
        }

        if ($search !== null && $search !== '') {
            $needle  = mb_strtolower($search);
            $entries = array_filter(
                $entries,
                fn ($e) => str_contains(mb_strtolower($e['message'] . ' ' . $e['context']), $needle),
            );
        }

        // Newest first — what you want when something just broke.
        return array_values(array_reverse($entries));
    }
}
