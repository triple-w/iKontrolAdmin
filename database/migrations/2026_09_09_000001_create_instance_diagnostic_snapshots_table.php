<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('instance_diagnostic_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instance_id')->constrained('ikontrol_instances')->cascadeOnDelete();
            $table->string('status', 20)->index();
            $table->json('checks');
            $table->json('recommendations')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->dateTime('checked_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instance_diagnostic_snapshots');
    }
};
