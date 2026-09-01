<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ikontrol_instances', function (Blueprint $table) {
            $table->string('schema_status', 30)->nullable()->index()->after('schema_version');
            $table->timestamp('schema_checked_at')->nullable()->after('schema_status');
            $table->text('schema_error')->nullable()->after('schema_checked_at');
        });
        Schema::create('instance_inspection_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instance_id')->constrained('ikontrol_instances')->cascadeOnDelete();
            $table->string('schema_status', 30)->index();
            $table->string('app_version')->nullable();
            $table->string('schema_version')->nullable();
            $table->string('company_name')->nullable();
            $table->string('legal_name')->nullable();
            $table->string('rfc', 30)->nullable();
            $table->string('url', 500)->nullable();
            $table->unsignedBigInteger('database_size')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->json('counts')->nullable();
            $table->json('technical_metadata')->nullable();
            $table->text('schema_error')->nullable();
            $table->timestamp('inspected_at')->index();
            $table->index(['instance_id', 'inspected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instance_inspection_snapshots');
        Schema::table('ikontrol_instances', fn (Blueprint $table) => $table->dropColumn(['schema_status', 'schema_checked_at', 'schema_error']));
    }
};
