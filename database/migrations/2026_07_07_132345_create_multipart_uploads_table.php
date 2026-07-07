<?php

use App\Models\File;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('multipart_uploads', function (Blueprint $table) {
            $table->id();

            $table->uuid('upload_id');
            $table->uuid('upload_file_id');

            $table->foreignIdFor(User::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignIdFor(File::class, 'parent_id')
                ->nullable()
                ->constrained('files')
                ->nullOnDelete();

            $table->string('client_id', 64);
            $table->string('name', 1024);
            $table->text('relative_path')->nullable();
            $table->string('content_type')->nullable();

            $table->unsignedBigInteger('size');
            $table->unsignedBigInteger('part_size');
            $table->unsignedInteger('part_count');
            $table->unsignedBigInteger('reserved_bytes')->default(0);

            $table->string('s3_key', 1024);
            $table->text('s3_upload_id');

            $table->string('status', 32)->index();

            $table->foreignIdFor(File::class, 'completed_file_id')
                ->nullable()
                ->constrained('files')
                ->nullOnDelete();

            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('aborted_at')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'upload_id', 'upload_file_id']);
            $table->index(['user_id', 'status']);
            $table->index(['upload_id', 'upload_file_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('multipart_uploads');
    }
};