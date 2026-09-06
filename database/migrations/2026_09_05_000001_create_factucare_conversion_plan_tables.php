<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('factucare_conversion_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('legacy_user_id');
            $table->string('legacy_rfc', 20);
            $table->foreignId('destination_instance_id')->nullable()->constrained('ikontrol_instances')->nullOnDelete();
            $table->string('status', 40);
            $table->json('options_json');
            $table->json('summary_json');
            $table->char('source_fingerprint', 64);
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['legacy_user_id', 'legacy_rfc']);
        });

        Schema::create('factucare_conversion_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('factucare_conversion_plans')->cascadeOnDelete();
            $table->string('entity_type', 40);
            $table->string('source_table');
            $table->string('source_id')->nullable();
            $table->string('proposed_action', 40);
            $table->string('validation_status', 40);
            $table->char('source_hash', 64);
            $table->json('warnings_json')->nullable();
            $table->json('preview_json')->nullable();
            $table->timestamps();

            $table->index(['plan_id', 'entity_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('factucare_conversion_plan_items');
        Schema::dropIfExists('factucare_conversion_plans');
    }
};
