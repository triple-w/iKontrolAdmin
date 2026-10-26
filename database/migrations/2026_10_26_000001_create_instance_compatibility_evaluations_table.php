<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('instance_compatibility_evaluations')) {
            throw new \RuntimeException('La tabla instance_compatibility_evaluations ya existe; revise el esquema parcial antes de continuar.');
        }
        Schema::create('instance_compatibility_evaluations', function (Blueprint $table) {
            $table->id();
            $table->uuid('evidence_id')->unique();
            $table->foreignId('instance_id')->constrained('ikontrol_instances')->cascadeOnDelete();
            $table->foreignId('release_id')->nullable()->constrained('ikontrol_releases')->nullOnDelete();
            $table->foreignId('evaluated_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('source_version', 80)->nullable();
            $table->string('target_version', 80);
            $table->char('source_commit_sha', 40)->nullable();
            $table->char('source_tree_sha256', 64)->nullable();
            $table->char('evaluated_release_commit_sha', 40)->nullable();
            $table->char('evaluated_release_artifact_sha256', 64)->nullable();
            $table->string('status', 30)->index();
            $table->string('bundle_trust', 30);
            $table->boolean('authorizes_deployment')->default(false);
            $table->char('contract_sha256', 64)->nullable();
            $table->char('bundle_sha256', 64)->nullable();
            $table->char('files_fingerprint_sha256', 64)->nullable();
            $table->char('schema_fingerprint_sha256', 64)->nullable();
            $table->json('summary')->nullable();
            $table->json('error_evidence')->nullable();
            $table->timestamp('evaluated_at');
            $table->timestamps();
            $table->index(['instance_id', 'target_version', 'evaluated_at'], 'compatibility_instance_target_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instance_compatibility_evaluations');
    }
};
