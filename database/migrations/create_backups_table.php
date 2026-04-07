<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('smart_backup_runs')) {
            Schema::create('smart_backup_runs', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('type', 20);
                $table->string('status', 20)->default('running');
                $table->string('format', 20)->nullable();
                $table->string('disk')->nullable();
                $table->string('base_path')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->text('error_message')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->index(['type', 'status']);
                $table->index('started_at');
            });
        }

        if (! Schema::hasTable('smart_backup_tables')) {
            Schema::create('smart_backup_tables', function (Blueprint $table) {
                $table->id();
                $table->foreignId('backup_run_id')
                    ->constrained('smart_backup_runs')
                    ->cascadeOnDelete();
                $table->string('table_name');
                $table->string('type', 20);
                $table->string('status', 20)->default('running');
                $table->string('file_path');
                $table->unsignedBigInteger('rows')->default(0);
                $table->unsignedInteger('chunks')->default(0);
                $table->timestamp('last_backup_at')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->index(['table_name', 'status']);
                $table->index(['table_name', 'last_backup_at']);
                $table->index('type');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_backup_tables');
        Schema::dropIfExists('smart_backup_runs');
    }
};
