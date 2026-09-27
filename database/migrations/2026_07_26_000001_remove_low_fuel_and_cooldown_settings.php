<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Keys removed from the Settings UI. The Alert Engine keeps working via its
     * hardcoded fallbacks (alert_cooldown => 30, low_fuel_pct => 20).
     */
    private array $keys = ['low_fuel_pct', 'alert_cooldown'];

    public function up(): void
    {
        DB::table('settings')->whereIn('key', $this->keys)->delete();
        DB::table('setting_changelogs')->whereIn('setting_key', $this->keys)->delete();
    }

    public function down(): void
    {
        DB::table('settings')->insert([
            [
                'key'         => 'low_fuel_pct',
                'value'       => '20',
                'type'        => 'integer',
                'label'       => 'Low Fuel Warning',
                'description' => 'Fuel percentage below which a low-fuel alert is triggered.',
                'group'       => 'alerts',
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'key'         => 'alert_cooldown',
                'value'       => '30',
                'type'        => 'integer',
                'label'       => 'Alert Cooldown',
                'description' => 'Minimum minutes between repeated alerts of the same type for the same vehicle.',
                'group'       => 'alerts',
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
        ]);
    }
};
