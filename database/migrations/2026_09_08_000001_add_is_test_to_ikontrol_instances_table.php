<?php
use Illuminate\Database\Migrations\Migration;use Illuminate\Database\Schema\Blueprint;use Illuminate\Support\Facades\Schema;
return new class extends Migration {public function up():void{if(!Schema::hasColumn('ikontrol_instances','is_test'))Schema::table('ikontrol_instances',fn(Blueprint $t)=>$t->boolean('is_test')->default(false)->index()->after('active'));}public function down():void{if(Schema::hasColumn('ikontrol_instances','is_test'))Schema::table('ikontrol_instances',fn(Blueprint $t)=>$t->dropColumn('is_test'));}};
