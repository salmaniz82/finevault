<?php

use App\Services\RelationalMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL is not transactional. The backfill is; archive/drop only follows successful verification.
        if (Schema::hasTable('portal_records')) {
            app(RelationalMigration::class)->backfill();
            Schema::rename('portal_records', 'legacy_portal_records');
        }
        if (Schema::hasColumn('users', 'manager_ids')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('manager_ids'));
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Restore a verified pre-cutover backup rather than discarding relational changes.');
    }
};
