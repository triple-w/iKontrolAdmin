<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ikontrol_templates', function (Blueprint $table) {
            $table->id();
            $table->string('version')->unique();
            $table->string('name');
            $table->string('app_version');
            $table->string('schema_version');
            $table->string('archive_path');
            $table->string('database_dump_path');
            $table->char('archive_sha256', 64);
            $table->char('database_sha256', 64);
            $table->boolean('active')->default(false)->index();
            $table->boolean('is_default')->default(false)->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('ikontrol_instances', function (Blueprint $table) {
            $table->foreignId('ikontrol_template_id')->nullable()->after('ikontrol_version_id')->constrained('ikontrol_templates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('ikontrol_instances', 'ikontrol_template_id')) {
            Schema::table('ikontrol_instances', fn (Blueprint $table) => $table->dropConstrainedForeignId('ikontrol_template_id'));
        }
        Schema::dropIfExists('ikontrol_templates');
    }
};
