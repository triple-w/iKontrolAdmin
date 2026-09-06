<?php

namespace App\Services;

use App\Models\{IkontrolInstance, IkontrolTemplate, IkontrolVersion};
use Illuminate\Support\Facades\File;
use RuntimeException;

class IkontrolDeploymentService
{
    public function __construct(private VersionSourceManager $sources, private AllowedArtisanRunner $artisan, private ?IkontrolTemplateValidationService $templates = null) {}

    public function deployTemplate(IkontrolInstance $instance, IkontrolTemplate $template): void
    {
        $path = $this->safeInstancePath($instance);
        $marker = $path.DIRECTORY_SEPARATOR.'.ikontrol-template.json';
        if ($instance->ikontrol_template_id !== $template->id) throw new RuntimeException('La plantilla no corresponde a la instalación.');
        if (File::exists($marker)) {
            $metadata = json_decode((string) File::get($marker), true);
            if (($metadata['template_id'] ?? null) !== $template->id || ($metadata['archive_sha256'] ?? null) !== $template->archive_sha256) {
                throw new RuntimeException('La carpeta contiene otra plantilla iKontrol.');
            }
            if (($metadata['status'] ?? null) === 'READY' && is_file($path.DIRECTORY_SEPARATOR.'artisan')) return;
        } elseif (File::exists($path) && count(File::allFiles($path)) > 0) {
            throw new RuntimeException('La carpeta de la instalación ya está ocupada.');
        }
        $validated = ($this->templates ?? app(IkontrolTemplateValidationService::class))->validate($template);
        File::ensureDirectoryExists($path, 0750);
        File::put($marker, json_encode(['template_id' => $template->id, 'archive_sha256' => $template->archive_sha256, 'status' => 'DEPLOYING'], JSON_THROW_ON_ERROR));
        $zip = new \ZipArchive();
        if ($zip->open($validated['archive']) !== true || ! $zip->extractTo($path)) throw new RuntimeException('No fue posible extraer la plantilla.');
        $zip->close();
        if (! is_file($path.DIRECTORY_SEPARATOR.'artisan') || ! is_file($path.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'index.php')) {
            throw new RuntimeException('La plantilla no contiene una aplicación iKontrol válida.');
        }
        foreach (['storage', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'] as $directory) {
            File::ensureDirectoryExists($path.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $directory), 0775);
        }
        $this->verifyDependencies($instance);
        File::put($marker, json_encode(['template_id' => $template->id, 'archive_sha256' => $template->archive_sha256, 'status' => 'READY'], JSON_THROW_ON_ERROR));
    }

    public function deployCode(IkontrolInstance $instance, IkontrolVersion $version): void
    {
        $path = $this->safeInstancePath($instance);
        $marker = $path.DIRECTORY_SEPARATOR.'.ikontrol-deployment.json';
        if (File::exists($marker)) {
            $metadata = json_decode((string) File::get($marker), true);
            if (($metadata['version_id'] ?? null) !== $version->id || ($metadata['source_reference'] ?? null) !== $version->source_reference) {
                throw new RuntimeException('La carpeta contiene otra versión de iKontrol.');
            }
            if (($metadata['status'] ?? null) === 'READY' && is_file($path.DIRECTORY_SEPARATOR.'artisan')) return;
        } elseif (File::exists($path) && count(File::allFiles($path)) > 0) {
            throw new RuntimeException('La carpeta de la instalación ya está ocupada.');
        }
        File::ensureDirectoryExists($path, 0750);
        File::put($marker, json_encode(['version_id' => $version->id, 'source_reference' => $version->source_reference, 'status' => 'DEPLOYING'], JSON_THROW_ON_ERROR));
        $this->sources->materialize($version, $path);
        if (! is_file($path.DIRECTORY_SEPARATOR.'artisan') || ! is_file($path.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'index.php')) {
            throw new RuntimeException('La fuente no contiene una aplicación iKontrol válida.');
        }
        foreach (['storage', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'] as $directory) {
            File::ensureDirectoryExists($path.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $directory), 0775);
        }
        File::put($marker, json_encode(['version_id' => $version->id, 'source_reference' => $version->source_reference, 'status' => 'READY'], JSON_THROW_ON_ERROR));
    }

    public function createEnvironment(IkontrolInstance $instance): void
    {
        $path = $this->safeInstancePath($instance);
        $target = $path.DIRECTORY_SEPARATOR.'.env';
        if (File::exists($target)) {
            $existing = (string) File::get($target);
            if (
                str_contains($existing, 'APP_ENV=production')
                && str_contains($existing, 'DB_DATABASE='.$this->envValue($instance->db_name))
            ) {
                return;
            }
            throw new RuntimeException('Ya existe un .env que no corresponde a esta instalación.');
        }
        $env = implode(PHP_EOL, [
            'APP_ENV=production',
            'APP_DEBUG=false',
            'APP_URL='.$this->envValue($instance->url ?: 'https://'.$instance->slug.'.ikontrol.solutions'),
            'APP_KEY=',
            '',
            'DB_CONNECTION=mysql',
            'DB_HOST='.$this->envValue($instance->db_host ?: config('ikontrol.db.host')),
            'DB_PORT='.($instance->db_port ?: config('ikontrol.db.port')),
            'DB_DATABASE='.$this->envValue($instance->db_name),
            'DB_USERNAME='.$this->envValue(config('ikontrol.db.username')),
            'DB_PASSWORD='.$this->envValue(config('ikontrol.db.password')),
            '',
        ]).PHP_EOL;
        $temporary = $target.'.tmp';
        if (File::put($temporary, $env) === false || ! File::move($temporary, $target)) {
            throw new RuntimeException('No fue posible generar el .env de la instalación.');
        }
        @chmod($target, 0600);
    }

    public function verifyDependencies(IkontrolInstance $instance): void
    {
        $path = $this->safeInstancePath($instance);
        if (! is_file($path.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php')) {
            throw new RuntimeException('La versión no incluye dependencias de producción. Genere el artefacto con Composer antes de registrarlo.');
        }
    }

    public function run(IkontrolInstance $instance, string $command, array $arguments = []): array
    {
        return $this->artisan->run($this->safeInstancePath($instance), $command, $arguments);
    }

    public function assertDatabaseNotExisting(string $database): void
    {
        if (! preg_match('/\A[a-zA-Z0-9_]+\z/', $database) || strlen($database) > 64) {
            throw new RuntimeException('Nombre de base inválido.');
        }
    }

    private function safeInstancePath(IkontrolInstance $instance): string
    {
        $root = realpath((string) config('ikontrol.instances_root'));
        $expectedFolder = $instance->slug.config('ikontrol.folder_suffix');
        $expected = $root === false ? '' : $this->normalizePath($root.DIRECTORY_SEPARATOR.$instance->folder_name);
        $path = $this->normalizePath($instance->absolute_path ?: $expected);
        $comparablePath = PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
        $comparableExpected = PHP_OS_FAMILY === 'Windows' ? strtolower($expected) : $expected;
        $comparableRoot = PHP_OS_FAMILY === 'Windows' ? strtolower((string) $root) : (string) $root;
        if ($root === false || $instance->folder_name !== $expectedFolder || $comparablePath !== $comparableExpected || str_contains($path, '..') || ! str_starts_with($comparablePath, $comparableRoot.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('La ruta de la instalación no es segura.');
        }
        if (is_link($path)) {
            throw new RuntimeException('La carpeta de la instalación no puede ser un symlink.');
        }
        return $path;
    }

    private function envValue(mixed $value): string
    {
        $value = str_replace(["\\", '"', '$', "\r", "\n"], ['\\\\', '\\"', '\\$', '', ''], (string) $value);
        return '"'.$value.'"';
    }

    private function normalizePath(string $path): string
    {
        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    }
}
