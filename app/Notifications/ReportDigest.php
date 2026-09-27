<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Scheduled daily/weekly fleet report digest — a short summary (fleet health,
 * headline KPIs, top findings) with a button through to the full report on the
 * dashboard. Sent on-demand to the addresses in the `report_email_recipients`
 * setting by the `reports:email` command.
 */
class ReportDigest extends Notification
{
    /**
     * @param array  $data        AnalyticsService::insights() payload
     * @param string $periodType  'Daily' | 'Weekly'
     * @param string $periodLabel e.g. "22 Sep 2026" or "16 Sep – 22 Sep 2026"
     * @param string $url         dashboard link to the full report
     */
    public function __construct(
        public array $data,
        public string $periodType,
        public string $periodLabel,
        public string $url,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $health = $this->data['health'] ?? [];
        $kpis   = $this->data['kpis'] ?? [];
        $score  = $health['score'] ?? '—';
        $label  = $health['label'] ?? '';

        $kpi = fn (string $k, string $suffix = '') => isset($kpis[$k]['value'])
            ? number_format((float) $kpis[$k]['value']) . $suffix
            : '—';

        $mail = (new MailMessage())
            ->subject("UNIFLEET {$this->periodType} Report — {$this->periodLabel}")
            ->greeting("UNIFLEET {$this->periodType} Report")
            ->line("Reporting period: {$this->periodLabel}")
            ->line("**Fleet Health:** {$score}/100" . ($label ? " ({$label})" : ''));

        if (! empty($this->data['summary_line'])) {
            $mail->line($this->data['summary_line']);
        }

        $mail->line('**Key metrics**')
            ->line('Active vehicles: ' . $kpi('active_vehicles'))
            ->line('Distance: ' . $kpi('distance_km', ' km'))
            ->line('Safety events: ' . $kpi('safety_events'))
            ->line('Idle time: ' . $kpi('idle_min', ' min'));

        $insights = array_slice($this->data['insights'] ?? [], 0, 3);
        if (! empty($insights)) {
            $mail->line('**Top findings**');
            foreach ($insights as $it) {
                if (! empty($it['title'])) {
                    $mail->line('• ' . $it['title']);
                }
            }
        }

        return $mail
            ->action('View full report', $this->url)
            ->line('This is an automated report from UNIFLEET Fleet Intelligence.');
    }
}
