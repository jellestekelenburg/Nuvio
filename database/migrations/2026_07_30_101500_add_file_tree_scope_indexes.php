<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table): void {
            $table->index(
                ['created_by', '_lft'],
                'files_owner_lft_index',
            );
            $table->index(
                ['created_by', '_rgt'],
                'files_owner_rgt_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table): void {
            $table->dropIndex('files_owner_lft_index');
            $table->dropIndex('files_owner_rgt_index');
        });
    }
};
