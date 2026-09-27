<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 3 user management. Three roles:
     *   - owner : super-account — manages admins, cannot be demoted/deleted by an admin.
     *   - admin : manages regular users, sees ALL vehicles, manages zones/sites.
     *   - user  : scoped by explicit grants (allowed_pages + user_zone/user_site).
     *
     * `is_admin` is retained and kept in sync (= owner|admin) so existing manager
     * gates (zones/contacts controllers, vehicleQuery) keep working unchanged.
     * `status` supports soft-delete (rows are kept for change-log tracing).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('user')->after('is_admin');           // owner|admin|user
            $table->string('status')->default('active')->after('role');           // active|disabled
            $table->json('allowed_pages')->nullable()->after('status');           // page keys a 'user' may see
            $table->boolean('can_edit_vehicles')->default(false)->after('allowed_pages');
        });

        // Backfill roles from the legacy is_admin flag.
        DB::table('users')->where('is_admin', true)->update(['role' => 'admin']);
        // The seeded default admin account is the top-level owner.
        DB::table('users')->where('dsco_username', 'admin')->update(['role' => 'owner', 'is_admin' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'status', 'allowed_pages', 'can_edit_vehicles']);
        });
    }
};
