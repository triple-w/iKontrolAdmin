<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Services\LegacyFc2\FactucareConnectionService;
use App\Services\LegacyFc2\FactucareReaderService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class FactucareReaderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'fc2.connection' => 'fc2_legacy',
            'fc2.per_page' => 1,
            'database.connections.fc2_legacy' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
        ]);
        $this->createLegacyFixture();
    }

    public function test_existing_rfc_is_normalized_and_resolves_profile_to_user(): void
    {
        $result = $this->reader()->findUserByRfc('  dold860620ew7  ');

        $this->assertTrue($result['found']);
        $this->assertSame(15, $result['users_id']);
        $this->assertSame('DOLD860620EW7', $result['rfc']);
        $this->assertSame('DENNISSE MILDRETH DOMINGUEZ LOPEZ', $result['profile']['legal_name']);
        $this->assertArrayNotHasKey('password', $result['user']);
    }

    public function test_unknown_rfc_returns_not_found(): void
    {
        $result = $this->reader()->findUserByRfc('XAXX010101000');

        $this->assertFalse($result['found']);
        $this->assertStringContainsString('No se encontró', $result['message']);
    }

    public function test_clients_are_scoped_to_user_searched_and_paginated_in_sql(): void
    {
        $result = $this->reader()->findUserByRfc('DOLD860620EW7', 'clients', 'Cliente');

        $this->assertSame(2, $result['clients']->total());
        $this->assertCount(1, $result['clients']->items());
        $this->assertSame(15, DB::connection('fc2_legacy')->table('clientes')->where('id', $result['clients']->first()->id)->value('users_id'));
    }

    public function test_products_are_scoped_and_resolve_sat_catalogs(): void
    {
        $result = $this->reader()->findUserByRfc('DOLD860620EW7', 'products', 'Servicio');
        $product = $result['products']->first();

        $this->assertSame(1, $result['products']->total());
        $this->assertSame('01010101', $product->sat_product_code);
        $this->assertSame('ACT', $product->sat_unit_code);
        $this->assertSame('No existe en el catálogo', $product->sat_product_description);
    }

    public function test_csd_reports_existence_without_returning_password_or_binary_content(): void
    {
        $result = $this->reader()->findUserByRfc('DOLD860620EW7', 'csd');
        $encoded = json_encode($result['csd']);

        $this->assertTrue($result['csd']['has_cer']);
        $this->assertTrue($result['csd']['has_key']);
        $this->assertStringNotContainsString('llave-secreta', $encoded);
        $this->assertStringNotContainsString('CONTENIDO-BINARIO', $encoded);
    }

    public function test_incomplete_schema_is_handled_without_exception(): void
    {
        DB::connection('fc2_legacy')->getSchemaBuilder()->drop('users_perfil');

        $result = $this->reader()->findUserByRfc('DOLD860620EW7');

        $this->assertFalse($result['found']);
        $this->assertStringContainsString('users_perfil.rfc', $result['message']);
    }

    public function test_connection_error_is_sanitized(): void
    {
        config(['database.connections.fc2_broken' => ['driver' => 'unsupported'], 'fc2.connection' => 'fc2_broken']);

        $result = $this->reader()->findUserByRfc('DOLD860620EW7');

        $this->assertFalse($result['found']);
        $this->assertSame('ERROR', $result['status']);
        $this->assertStringNotContainsString('password', strtolower($result['message']));
    }

    public function test_safe_connection_check_counts_users(): void
    {
        config(['fc2.host' => 'localhost', 'fc2.database' => 'fixture', 'fc2.username' => 'reader', 'fc2.password' => 'secret']);

        $result = (new FactucareConnectionService())->testConnection();

        $this->assertTrue($result['success']);
        $this->assertSame('CONNECTED', $result['status']);
        $this->assertSame(2, $result['users_count']);
        $this->assertStringNotContainsString('secret', json_encode($result));
    }

    public function test_reader_executes_no_mutating_statements(): void
    {
        $queries = [];
        Event::listen(QueryExecuted::class, function (QueryExecuted $event) use (&$queries) {
            if ($event->connectionName === 'fc2_legacy') $queries[] = $event->sql;
        });

        $this->reader()->findUserByRfc('DOLD860620EW7', 'products');

        $this->assertNotEmpty($queries);
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/\b(INSERT|UPDATE|DELETE|CREATE|ALTER|DROP|TRUNCATE)\b/i', $query);
        }
    }

    public function test_ui_and_audit_do_not_render_sensitive_values(): void
    {
        $this->withoutVite();
        $admin = AdminUser::create(['name' => 'Admin', 'email' => 'fc2@example.test', 'password' => 'admin-test-password', 'active' => true]);

        $this->actingAs($admin)->post(route('legacy.factucare.search'), ['rfc' => ' dold860620ew7 '])->assertRedirect(route('legacy.factucare.users.show', 15));
        $response = $this->actingAs($admin)->get(route('legacy.factucare.users.show', ['user' => 15, 'section' => 'csd']));

        $response->assertOk()->assertSee('DOLD860620EW7')->assertDontSee('llave-secreta')->assertDontSee('CONTENIDO-BINARIO');
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'factucare_search']);
    }

    private function reader(): FactucareReaderService
    {
        return new FactucareReaderService(new FactucareConnectionService());
    }

    private function createLegacyFixture(): void
    {
        $schema = DB::connection('fc2_legacy')->getSchemaBuilder();
        $schema->create('users', function (Blueprint $table) { $table->integer('id')->primary(); $table->string('nombre'); $table->string('email'); $table->string('password'); $table->boolean('activo'); $table->timestamp('created_at')->nullable(); });
        $schema->create('users_perfil', function (Blueprint $table) { $table->increments('id'); $table->integer('users_id'); $table->string('rfc'); $table->string('razon_social'); $table->string('regimen_fiscal')->nullable(); $table->string('codigo_postal')->nullable(); });
        $schema->create('clientes', function (Blueprint $table) { $table->increments('id'); $table->integer('users_id'); $table->string('rfc'); $table->string('razon_social'); $table->string('email')->nullable(); });
        $schema->create('clave_prod_serv', function (Blueprint $table) { $table->integer('id')->primary(); $table->string('clave'); $table->string('descripcion'); });
        $schema->create('clave_unidad', function (Blueprint $table) { $table->integer('id')->primary(); $table->string('clave'); $table->string('descripcion'); });
        $schema->create('productos', function (Blueprint $table) { $table->increments('id'); $table->integer('users_id'); $table->string('clave'); $table->string('descripcion'); $table->decimal('precio', 10, 2); $table->integer('clave_prod_serv_id'); $table->integer('clave_unidad_id'); });
        $schema->create('users_info_factura', function (Blueprint $table) { $table->increments('id'); $table->integer('users_id'); $table->string('numero_certificado')->nullable(); $table->text('cer')->nullable(); $table->text('key')->nullable(); $table->string('password_key')->nullable(); });
        $schema->create('users_info_factura_documentos', function (Blueprint $table) { $table->increments('id'); $table->integer('users_info_factura_id'); $table->string('tipo'); $table->string('nombre_archivo'); $table->binary('contenido')->nullable(); });
        foreach (['folios', 'facturas', 'complementos', 'cancelaciones', 'timbres_movs'] as $tableName) $schema->create($tableName, function (Blueprint $table) { $table->increments('id'); $table->integer('users_id'); });

        $db = DB::connection('fc2_legacy');
        $db->table('users')->insert([['id'=>15,'nombre'=>'Dennisse','email'=>'legacy@example.test','password'=>'hash-ultra-secreto','activo'=>1],['id'=>99,'nombre'=>'Otro','email'=>'other@example.test','password'=>'otro-secreto','activo'=>1]]);
        $db->table('users_perfil')->insert(['users_id'=>15,'rfc'=>'DOLD860620EW7','razon_social'=>'DENNISSE MILDRETH DOMINGUEZ LOPEZ','regimen_fiscal'=>'612','codigo_postal'=>'00000']);
        $db->table('clientes')->insert([['users_id'=>15,'rfc'=>'AAA010101AAA','razon_social'=>'Cliente Uno'],['users_id'=>15,'rfc'=>'BBB010101BBB','razon_social'=>'Cliente Dos'],['users_id'=>99,'rfc'=>'CCC010101CCC','razon_social'=>'Cliente Ajeno']]);
        $db->table('clave_prod_serv')->insert(['id'=>1,'clave'=>'01010101','descripcion'=>'No existe en el catálogo']);
        $db->table('clave_unidad')->insert(['id'=>1,'clave'=>'ACT','descripcion'=>'Actividad']);
        $db->table('productos')->insert([['users_id'=>15,'clave'=>'SERV-1','descripcion'=>'Servicio DOLD','precio'=>100,'clave_prod_serv_id'=>1,'clave_unidad_id'=>1],['users_id'=>99,'clave'=>'AJENO','descripcion'=>'Servicio ajeno','precio'=>50,'clave_prod_serv_id'=>1,'clave_unidad_id'=>1]]);
        $db->table('users_info_factura')->insert(['id'=>7,'users_id'=>15,'numero_certificado'=>'30001000000500003416','cer'=>'CONTENIDO-BINARIO','key'=>'CONTENIDO-BINARIO','password_key'=>'llave-secreta']);
        $db->table('users_info_factura_documentos')->insert([['users_info_factura_id'=>7,'tipo'=>'CER','nombre_archivo'=>'certificado.cer','contenido'=>'CONTENIDO-BINARIO'],['users_info_factura_id'=>7,'tipo'=>'KEY','nombre_archivo'=>'llave.key','contenido'=>'CONTENIDO-BINARIO']]);
    }
}
