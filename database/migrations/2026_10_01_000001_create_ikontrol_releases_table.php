<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ikontrol_releases', function (Blueprint $table) {
            $table->id();
            $table->string('version', 80)->unique();
            $table->string('channel', 20)->index();
            $table->string('git_tag', 100)->unique();
            $table->char('commit_sha', 40);
            $table->string('source_repository', 255);
            $table->char('manifest_hash', 64)->nullable();
            $table->json('manifest_json')->nullable();
            $table->json('validation_errors')->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamp('discovered_at')->nullable()->index();
            $table->string('status', 20)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ikontrol_releases');
    }
};
