<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ikontrol_instances', function (Blueprint $table) {
            $table->string('canonical_version', 80)->nullable()->after('detected_version');
            $table->string('database_status', 30)->nullable()->after('canonical_version');
            $table->string('baseline_status', 30)->nullable()->after('database_status');
        });
    }

    public function down(): void
    {
        Schema::table('ikontrol_instances', fn (Blueprint $table) => $table->dropColumn(['canonical_version', 'database_status', 'baseline_status']));
    }
};
