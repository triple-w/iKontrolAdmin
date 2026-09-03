<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ikontrol_instances', function (Blueprint $table) {
            $table->foreignId('ikontrol_version_id')->nullable()->after('client_id')->constrained('ikontrol_versions')->nullOnDelete();
            $table->string('installed_version', 50)->nullable()->after('app_version');
            $table->timestamp('installed_at')->nullable()->after('installed_version');
        });
    }

    public function down(): void
    {
        Schema::table('ikontrol_instances', function (Blueprint $table) {
            $table->dropForeign(['ikontrol_version_id']);
            $table->dropColumn(['ikontrol_version_id', 'installed_version', 'installed_at']);
        });
    }
};
