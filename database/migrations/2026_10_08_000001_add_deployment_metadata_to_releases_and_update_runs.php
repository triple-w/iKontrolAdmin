<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ikontrol_releases', function (Blueprint $table) {
            $table->string('release_identifier', 160)->nullable()->after('version');
            $table->string('source_ref', 160)->nullable()->after('git_tag');
            $table->string('artifact_url', 1000)->nullable()->after('source_repository');
            $table->string('artifact_path', 500)->nullable()->after('artifact_url');
            $table->char('artifact_sha256', 64)->nullable()->after('artifact_path');
            $table->string('artifact_verification_status', 30)->default('unverified')->index()->after('artifact_sha256');
            $table->json('artifact_manifest_json')->nullable()->after('artifact_verification_status');
        });

        Schema::table('instance_update_runs', function (Blueprint $table) {
            $table->json('deployment_plan')->nullable()->after('backup_reference');
            $table->json('backup_manifest')->nullable()->after('deployment_plan');
            $table->string('staging_reference', 500)->nullable()->after('backup_manifest');
            $table->string('rollback_status', 40)->nullable()->after('staging_reference');
            $table->boolean('database_review_required')->default(false)->after('rollback_status');
        });

        Schema::table('ikontrol_instances', function (Blueprint $table) {
            $table->json('deployment_overrides')->nullable()->after('baseline_status');
            $table->boolean('is_release_canary')->default(false)->index()->after('deployment_overrides');
        });
    }

    public function down(): void
    {
        Schema::table('ikontrol_instances', function (Blueprint $table) {
            $table->dropIndex(['is_release_canary']);
            $table->dropColumn(['deployment_overrides', 'is_release_canary']);
        });
        Schema::table('instance_update_runs', fn (Blueprint $table) => $table->dropColumn(['deployment_plan', 'backup_manifest', 'staging_reference', 'rollback_status', 'database_review_required']));
        Schema::table('ikontrol_releases', function (Blueprint $table) {
            $table->dropIndex(['artifact_verification_status']);
            $table->dropColumn(['release_identifier', 'source_ref', 'artifact_url', 'artifact_path', 'artifact_sha256', 'artifact_verification_status', 'artifact_manifest_json']);
        });
    }
};
