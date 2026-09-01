<?php
use Illuminate\Database\Migrations\Migration; use Illuminate\Database\Schema\Blueprint; use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up(): void { Schema::create('admin_users', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('email')->unique(); $t->string('password'); $t->boolean('active')->default(true)->index(); $t->timestamp('last_login_at')->nullable(); $t->string('last_login_ip',45)->nullable(); $t->rememberToken(); $t->timestamps(); }); } public function down(): void { Schema::dropIfExists('admin_users'); } };
