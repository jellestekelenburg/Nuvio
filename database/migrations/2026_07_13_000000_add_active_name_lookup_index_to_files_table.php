<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add an index for active name lookups within a directory.
     */
    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            // Keep the composite key below InnoDB's utf8mb4 index-size limit.
            DB::statement(
                'CREATE INDEX files_parent_active_name_index '
                .'ON files (parent_id, deleted_at, name(191))',
            );

            return;
        }

        Schema::table('files', function (Blueprint $table) {
            $table->index(
                ['parent_id', 'deleted_at', 'name'],
                'files_parent_active_name_index',
            );
        });
    }

    /**
     * Remove the active directory-name lookup index.
     */
    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropIndex('files_parent_active_name_index');
        });
    }
};
