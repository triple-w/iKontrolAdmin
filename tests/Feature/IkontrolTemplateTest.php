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

    public function test_dangerous_zip_is_rejected(): void
    {
        $template = $this->template(['../escape.php' => '<?php']);
        $this->expectException(RuntimeException::class);
        app(IkontrolTemplateValidationService::class)->validate($template);
    }

    public function test_zip_with_env_is_rejected(): void
    {
        $template = $this->template(['.env' => 'APP_KEY=secret']);
        $this->expectException(RuntimeException::class);
        app(IkontrolTemplateValidationService::class)->validate($template);
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
        foreach (['artisan' => '#!/usr/bin/env php', 'public/index.php' => '<?php', 'composer.json' => '{}', 'vendor/autoload.php' => '<?php'] + $extraEntries as $name => $contents) $zip->addFromString($name, $contents);
        $zip->close(); File::put($sql, 'CREATE TABLE example (id INT);');
        return new IkontrolTemplate(['version' => '1.0.0', 'name' => 'Base', 'app_version' => '1.0.0', 'schema_version' => '1', 'archive_path' => '1.0.0/ikontrol-1.0.0.zip', 'database_dump_path' => '1.0.0/ikontrol-1.0.0.sql', 'archive_sha256' => hash_file('sha256', $archive), 'database_sha256' => hash_file('sha256', $sql), 'active' => true]);
    }
}
