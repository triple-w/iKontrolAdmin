<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ikontrol_instances', function (Blueprint $table) {
            $table->string('current_version', 80)->nullable()->after('installed_version');
            $table->char('current_commit_sha', 40)->nullable()->after('current_version');
            $table->string('update_channel', 20)->default('stable')->index()->after('current_commit_sha');
            $table->timestamp('last_update_at')->nullable()->after('update_channel');
            $table->string('last_update_status', 30)->nullable()->after('last_update_at');
        });

        DB::table('ikontrol_instances')->whereNull('current_version')->update([
            'current_version' => DB::raw('COALESCE(installed_version, detected_version, app_version)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('ikontrol_instances', function (Blueprint $table) {
            $table->dropIndex(['update_channel']);
            $table->dropColumn(['current_version', 'current_commit_sha', 'update_channel', 'last_update_at', 'last_update_status']);
        });
    }
};
