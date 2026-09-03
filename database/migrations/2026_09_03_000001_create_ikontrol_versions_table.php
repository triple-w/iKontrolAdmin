<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ikontrol_versions', function (Blueprint $table) {
            $table->id();
            $table->string('version', 50)->unique();
            $table->string('name');
            $table->string('source_type', 20);
            $table->string('source_reference');
            $table->string('checksum', 128)->nullable();
            $table->boolean('active')->default(true)->index();
            $table->boolean('is_default')->default(false)->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ikontrol_versions');
    }
};
