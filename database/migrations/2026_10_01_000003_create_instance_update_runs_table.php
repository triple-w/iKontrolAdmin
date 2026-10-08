<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instance_update_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instance_id')->constrained('ikontrol_instances')->cascadeOnDelete();
            $table->string('from_version', 80)->nullable();
            $table->string('to_version', 80);
            $table->foreignId('release_id')->nullable()->constrained('ikontrol_releases')->nullOnDelete();
            $table->string('status', 30)->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('backup_reference', 500)->nullable();
            $table->longText('log')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->index(['instance_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instance_update_runs');
    }
};
