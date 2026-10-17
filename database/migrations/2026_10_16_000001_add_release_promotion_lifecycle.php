<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ikontrol_releases', function (Blueprint $table) {
            $table->string('promotion_status', 20)->default('BUILT')->index()->after('status');
        });
        DB::table('ikontrol_releases')->where('status', 'deprecated')->update(['promotion_status' => 'DEPRECATED']);

        Schema::table('instance_update_runs', function (Blueprint $table) {
            $table->char('release_commit_sha', 40)->nullable()->after('release_id');
            $table->char('release_artifact_sha256', 64)->nullable()->after('release_commit_sha');
        });

        Schema::create('release_promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ikontrol_release_id')->constrained('ikontrol_releases')->restrictOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('actor_user_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->foreignId('instance_update_run_id')->nullable()->constrained('instance_update_runs')->restrictOnDelete();
            $table->char('commit_sha', 40)->nullable();
            $table->char('artifact_sha256', 64)->nullable();
            $table->json('evidence')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['ikontrol_release_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('release_promotions');

        Schema::table('instance_update_runs', function (Blueprint $table) {
            $table->dropColumn(['release_commit_sha', 'release_artifact_sha256']);
        });

        Schema::table('ikontrol_releases', function (Blueprint $table) {
            $table->dropIndex(['promotion_status']);
            $table->dropColumn('promotion_status');
        });
    }
};
