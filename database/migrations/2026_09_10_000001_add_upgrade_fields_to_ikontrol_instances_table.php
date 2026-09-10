<?php
use Illuminate\Database\Migrations\Migration; use Illuminate\Database\Schema\Blueprint; use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::table('ikontrol_instances', function(Blueprint $t){ $t->string('installation_origin',20)->default('NEW')->index()->after('installation_status'); $t->string('detected_version',80)->nullable()->after('installed_version'); $t->string('target_version',50)->nullable()->after('detected_version'); $t->string('upgrade_status',30)->default('NOT_AUDITED')->index()->after('target_version'); $t->timestamp('last_upgrade_audit_at')->nullable()->after('upgrade_status'); }); }
 public function down(): void { Schema::table('ikontrol_instances',fn(Blueprint $t)=>$t->dropColumn(['installation_origin','detected_version','target_version','upgrade_status','last_upgrade_audit_at'])); }
};
