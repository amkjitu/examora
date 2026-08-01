<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Private questions were previously marked only in `meta`, which nothing
     * enforced — so a student's uploaded questions leaked into every other
     * student's practice pool.
     *
     * Ownership becomes a real column with a global scope on the model, so the
     * safe behaviour is the default and a future query cannot leak by forgetting
     * a filter. That is exactly how the original bug happened.
     */
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            // Null means shared: the exam-wide bank every student practises from.
            $table->foreignId('owner_id')->nullable()->after('exam_id')
                ->constrained('users')->cascadeOnDelete();

            $table->index(['exam_id', 'owner_id', 'is_active']);
        });

        // Backfill the marker that used to live in meta.
        $driver = DB::connection()->getDriverName();

        $ownerExpression = match ($driver) {
            'pgsql' => "(meta::json->>'private_to_user_id')",
            'mysql', 'mariadb' => "json_unquote(json_extract(meta, '$.private_to_user_id'))",
            default => "json_extract(meta, '$.private_to_user_id')",
        };

        DB::statement("update questions set owner_id = cast({$ownerExpression} as integer) where {$ownerExpression} is not null");
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropIndex(['exam_id', 'owner_id', 'is_active']);
            $table->dropConstrainedForeignId('owner_id');
        });
    }
};
