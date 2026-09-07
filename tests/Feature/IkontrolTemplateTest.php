<?php

namespace Tests\Feature;

use App\Models\IkontrolTemplate;
use App\Services\IkontrolTemplateValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class IkontrolTemplateTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/templates-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($this->root.'/1.0.0');
        config(['ikontrol.templates.root' => $this->root]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_valid_template_requires_matching_code_and_database_pair(): void
    {
        $template = $this->template();
        $result = app(IkontrolTemplateValidationService::class)->validate($template);

        $this->assertFileExists($result['archive']);
        $this->assertFileExists($result['database']);
    }

    public function test_invalid_archive_checksum_is_rejected(): void
    {
        $template = $this->template();
        $template->archive_sha256 = str_repeat('0', 64);
        $this->expectException(RuntimeException::class);
        app(IkontrolTemplateValidationService::class)->validate($template);
    }

    public function test_traversal_is_rejected(): void
    {
        $template = $this->template(['../escape.php' => '<?php']);
        $this->expectException(RuntimeException::class);
        app(IkontrolTemplateValidationService::class)->validate($template);
    }

    public function test_empty_writable_directory_is_allowed(): void
    {
        $this->assertValidArchiveWith(['writable/' => null]);
    }

    public function test_writable_cache_is_allowed(): void
    {
        $this->assertValidArchiveWith(['writable/' => null, 'writable/cache/' => null]);
    }

    public function test_writable_session_is_allowed(): void
    {
        $this->assertValidArchiveWith(['writable/' => null, 'writable/session/' => null]);
    }

    public function test_empty_writable_logs_is_allowed(): void
    {
        $this->assertValidArchiveWith(['writable/' => null, 'writable/logs/' => null]);
    }

    public function test_empty_writable_fiscal_certificates_is_allowed(): void
    {
        $this->assertValidArchiveWith(['writable/' => null, 'writable/fiscal/' => null, 'writable/fiscal/certificates/' => null]);
    }

    public function test_writable_backups_is_rejected(): void
    {
        $this->assertArchiveRejected(['writable/backups/backup.zip' => 'backup']);
    }

    public function test_real_writable_log_is_rejected(): void
    {
        $this->assertArchiveRejected(['writable/logs/app.log' => 'log']);
    }

    public function test_cer_is_rejected(): void
    {
        $this->assertArchiveRejected(['writable/fiscal/certificates/archivo.cer' => 'certificate']);
    }

    public function test_private_key_is_rejected(): void
    {
        $this->assertArchiveRejected(['writable/fiscal/certificates/archivo.key' => 'private']);
    }

    public function test_symlink_is_rejected(): void
    {
        $template = $this->template(['writable/link' => 'target']);
        $zip = new ZipArchive(); $zip->open($this->root.'/1.0.0/ikontrol-1.0.0.zip');
        $zip->setExternalAttributesName('writable/link', ZipArchive::OPSYS_UNIX, 0120777 << 16); $zip->close();
        $template->archive_sha256 = hash_file('sha256', $this->root.'/1.0.0/ikontrol-1.0.0.zip');
        $this->expectException(RuntimeException::class); app(IkontrolTemplateValidationService::class)->validate($template);
    }

    public function test_zip_with_env_is_rejected(): void
    {
        $template = $this->template(['.env' => 'APP_KEY=secret']);
        $this->expectException(RuntimeException::class);
        app(IkontrolTemplateValidationService::class)->validate($template);
    }

    public function test_zip_with_runtime_data_or_real_csd_is_rejected(): void
    {
        foreach (['writable/logs/app.log'=>'log', 'backups/customer.sql'=>'secret', 'app/Certificates/real.key'=>'private'] as $path=>$contents) {
            $template = $this->template([$path=>$contents]);
            try { app(IkontrolTemplateValidationService::class)->validate($template); $this->fail("{$path} debió rechazarse"); } catch (RuntimeException) { $this->assertTrue(true); }
        }
    }

    public function test_invalid_database_checksum_is_rejected(): void
    {
        $template = $this->template(); $template->database_sha256 = str_repeat('0',64);
        $this->expectException(RuntimeException::class); app(IkontrolTemplateValidationService::class)->validate($template);
    }

    public function test_missing_sql_is_rejected(): void
    {
        $template = $this->template();
        File::delete($this->root.'/1.0.0/ikontrol-1.0.0.sql');
        $this->expectException(RuntimeException::class);
        app(IkontrolTemplateValidationService::class)->validate($template);
    }

    public function test_relative_path_traversal_is_rejected(): void
    {
        $template = $this->template();
        $template->archive_path = '../ikontrol-1.0.0.zip';
        $this->expectException(RuntimeException::class);
        app(IkontrolTemplateValidationService::class)->validate($template);
    }

    private function template(array $extraEntries = []): IkontrolTemplate
    {
        $archive = $this->root.'/1.0.0/ikontrol-1.0.0.zip';
        $sql = $this->root.'/1.0.0/ikontrol-1.0.0.sql';
        $zip = new ZipArchive(); $zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach (['index.php' => '<?php', 'spark' => '#!/usr/bin/env php', '.env.example' => 'CI_ENVIRONMENT = production', 'app/Config/App.php' => '<?php', 'system/CodeIgniter.php' => '<?php'] + $extraEntries as $name => $contents) $contents === null ? $zip->addEmptyDir(rtrim($name, '/')) : $zip->addFromString($name, $contents);
        $zip->close(); File::put($sql, 'CREATE TABLE example (id INT);');
        return new IkontrolTemplate(['version' => '1.0.0', 'name' => 'Base', 'app_version' => '1.0.0', 'schema_version' => '1', 'archive_path' => '1.0.0/ikontrol-1.0.0.zip', 'database_dump_path' => '1.0.0/ikontrol-1.0.0.sql', 'archive_sha256' => hash_file('sha256', $archive), 'database_sha256' => hash_file('sha256', $sql), 'active' => true]);
    }

    private function assertValidArchiveWith(array $entries): void
    {
        $result = app(IkontrolTemplateValidationService::class)->validate($this->template($entries));
        $this->assertFileExists($result['archive']);
    }

    private function assertArchiveRejected(array $entries): void
    {
        try { app(IkontrolTemplateValidationService::class)->validate($this->template($entries)); $this->fail('El contenido sensible debió rechazarse.'); } catch (RuntimeException) { $this->assertTrue(true); }
    }
}
