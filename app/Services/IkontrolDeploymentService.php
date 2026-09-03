<?php

namespace App\Services;

use App\Models\{IkontrolInstance, IkontrolVersion};
use Illuminate\Support\Facades\File;
use RuntimeException;

class IkontrolDeploymentService
{
    public function __construct(private VersionSourceManager $sources, private AllowedArtisanRunner $artisan) {}

    public function deployCode(IkontrolInstance $instance, IkontrolVersion $version): void
    {
        $path = $this->safeInstancePath($instance);
        if (File::exists($path) && count(File::allFiles($path)) > 0) {
            throw new RuntimeException('La carpeta de la instalación ya está ocupada.');
        }
        File::ensureDirectoryExists($path, 0750);
        $this->sources->materialize($version, $path);
        if (! is_file($path.DIRECTORY_SEPARATOR.'artisan')) {
            throw new RuntimeException('La fuente no contiene una aplicación iKontrol válida.');
        }
    }

    public function createEnvironment(IkontrolInstance $instance): void
    {
        $path = $this->safeInstancePath($instance);
        $env = implode(PHP_EOL, [
            'APP_ENV=production',
            'APP_DEBUG=false',
            'APP_URL='.($instance->url ?: 'https://'.$instance->slug.'.ikontrol.solutions'),
            'APP_KEY=',
            '',
            'DB_CONNECTION=mysql',
            'DB_HOST='.($instance->db_host ?: config('ikontrol.db.host')),
            'DB_PORT='.($instance->db_port ?: config('ikontrol.db.port')),
            'DB_DATABASE='.$instance->db_name,
            'DB_USERNAME='.config('ikontrol.db.username'),
            'DB_PASSWORD='.config('ikontrol.db.password'),
            '',
        ]).PHP_EOL;
        if (File::put($path.DIRECTORY_SEPARATOR.'.env', $env) === false) {
            throw new RuntimeException('No fue posible generar el .env de la instalación.');
        }
        @chmod($path.DIRECTORY_SEPARATOR.'.env', 0600);
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
        $expected = rtrim((string) config('ikontrol.instances_root'), '/\\').DIRECTORY_SEPARATOR.$instance->folder_name;
        $path = $instance->absolute_path ?: $expected;
        if ($root === false || $path !== $expected || str_contains($path, '..') || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('La ruta de la instalación no es segura.');
        }
        if (is_link($path)) {
            throw new RuntimeException('La carpeta de la instalación no puede ser un symlink.');
        }
        return $path;
    }
}
