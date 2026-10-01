<?php

namespace App\Console\Commands;

use App\Models\ProviderApiFailure;
use App\Services\SystemHealthService;
use Illuminate\Console\Command;

/**
 * One command that answers "is everything OK?".
 *
 *   php artisan unifleet:doctor            # human-readable table
 *   php artisan unifleet:doctor --json     # machine-readable
 *   php artisan unifleet:doctor --prune    # trim old provider-failure rows
 *
 * Exits 1 when any check fails, so it also works from cron or CI.
 * All the logic lives in SystemHealthService — this is presentation only.
 */
class DoctorCommand extends Command
{
    protected $signature = 'unifleet:doctor
                            {--json       : Output the raw report as JSON}
                            {--prune      : Delete provider_api_failures rows older than the retention window}
                            {--days=30    : Retention window used by --prune}';

    protected $description = 'Full system health check — DB, migrations, poller freshness, provider status, queue, disk, logs, alert delivery.';

    public function handle(SystemHealthService $health): int
    {
        if ($this->option('prune')) {
            return $this->prune((int) $this->option('days'));
        }

        $report = $health->report();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $report['status'] === SystemHealthService::FAIL ? self::FAILURE : self::SUCCESS;
        }

        $this->renderTable($report);

        return $report['status'] === SystemHealthService::FAIL ? self::FAILURE : self::SUCCESS;
    }

    private function renderTable(array $report): void
    {
        $this->newLine();
        $this->line('  <options=bold>UNIFLEET system check</> — ' . $report['generated_at']);
        $this->newLine();

        foreach ($report['checks'] as $check) {
            [$icon, $style] = match ($check['status']) {
                SystemHealthService::OK   => ['✓', 'info'],
                SystemHealthService::WARN => ['!', 'comment'],
                default                   => ['✗', 'error'],
            };

            $label = str_pad($check['label'], 20);
            $value = $check['value'] ?? '';

            $this->line("  <{$style}>{$icon}</{$style}>  {$label} <options=bold>{$value}</>");

            // Only show the explanation when something needs attention — a clean
            // run should be scannable in one glance.
            if ($check['status'] !== SystemHealthService::OK && ! empty($check['detail'])) {
                $this->line("       <fg=gray>{$check['detail']}</>");
            }
        }

        // Per-provider detail: the "when did Safee fail?" answer.
        $providers = collect($report['checks'])->firstWhere('key', 'providers')['extra']['providers'] ?? [];

        if ($providers !== []) {
            $this->newLine();
            $this->line('  <options=bold>Providers</>');

            foreach ($providers as $name => $p) {
                $this->line("    {$name}: last success " . ($p['last_success_at'] ?? 'never')
                    . " · {$p['failures_last_hour']} failure(s) in the last hour");

                if ($p['last_failure']) {
                    $f = $p['last_failure'];
                    $this->line("      <fg=gray>last failure {$f['at']} · {$f['kind']} · "
                        . ($f['endpoint'] ?? '-') . ' · HTTP ' . ($f['status_code'] ?? 'n/a') . '</>');
                }
            }
        }

        if (! empty($report['counts'])) {
            $this->newLine();
            $this->line('  <options=bold>Data</>');
            foreach ($report['counts'] as $k => $v) {
                $this->line('    ' . str_pad(str_replace('_', ' ', $k), 18) . number_format((float) $v));
            }
        }

        $this->newLine();

        $overall = match ($report['status']) {
            SystemHealthService::OK   => '<info>ALL OK</info>',
            SystemHealthService::WARN => '<comment>OK WITH WARNINGS</comment>',
            default                   => '<error>FAILING</error>',
        };

        $this->line("  Overall: {$overall}");
        $this->newLine();
    }

    private function prune(int $days): int
    {
        $cutoff  = now()->subDays($days);
        $deleted = ProviderApiFailure::where('occurred_at', '<', $cutoff)->delete();

        $this->info("Pruned {$deleted} provider API failure row(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
