<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ikontrol_instances', 'schema_status')) {
            Schema::table('ikontrol_instances', function (Blueprint $table) {
                $table->string('schema_status', 30)->nullable()->index()->after('schema_version');
            });
        }

        if (! Schema::hasColumn('ikontrol_instances', 'schema_checked_at')) {
            Schema::table('ikontrol_instances', function (Blueprint $table) {
                $table->dateTime('schema_checked_at')->nullable()->after('schema_status');
            });
        }

        if (! Schema::hasColumn('ikontrol_instances', 'schema_error')) {
            Schema::table('ikontrol_instances', function (Blueprint $table) {
                $table->text('schema_error')->nullable()->after('schema_checked_at');
            });
        }

        if (! Schema::hasTable('instance_inspection_snapshots')) {
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
                $table->dateTime('last_activity_at')->nullable();
                $table->json('counts')->nullable();
                $table->json('technical_metadata')->nullable();
                $table->text('schema_error')->nullable();
                $table->dateTime('inspected_at')->index();
                $table->index(['instance_id', 'inspected_at']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('instance_inspection_snapshots')) {
            Schema::drop('instance_inspection_snapshots');
        }

        foreach (['schema_error', 'schema_checked_at', 'schema_status'] as $column) {
            if (Schema::hasColumn('ikontrol_instances', $column)) {
                Schema::table('ikontrol_instances', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
