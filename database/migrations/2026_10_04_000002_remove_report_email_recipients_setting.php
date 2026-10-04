<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retires the `report_email_recipients` setting.
 *
 * It is superseded by per-event recipients (`email_events`, 2026-10-04), which
 * cover the same two report emails plus To/CC for everything else. Keeping both
 * meant two places could disagree about who gets the daily report.
 *
 * Any configured value is copied into the `report_daily` / `report_weekly` rows
 * first — only where those are still empty, so an admin's newer choice always
 * wins — so no install silently stops emailing its reports.
 */
return new class extends Migration
{
    private const KEY = 'report_email_recipients';

    public function up(): void
    {
        $legacy = trim((string) DB::table('settings')->where('key', self::KEY)->value('value'));

        if ($legacy !== '') {
            foreach (['report_daily', 'report_weekly'] as $event) {
                DB::table('email_events')
                    ->where('event', $event)
                    // Never overwrite a value set through the new UI.
                    ->where(fn ($q) => $q->whereNull('to')->orWhere('to', ''))
                    ->update(['to' => $legacy, 'updated_at' => now()]);
            }
        }

        DB::table('settings')->where('key', self::KEY)->delete();
        DB::table('setting_changelogs')->where('setting_key', self::KEY)->delete();
    }

    public function down(): void
    {
        DB::table('settings')->insert([
            'key'         => self::KEY,
            'value'       => '',
            'type'        => 'string',
            'label'       => 'Report Email Recipients',
            'description' => 'Comma-separated email addresses that receive the automated daily and weekly fleet report digests. Leave blank to disable scheduled report emails.',
            'group'       => 'reports',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }
};
