<?php

namespace App\Console\Commands;

use App\Models\IkontrolInstance;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

class BuildIkontrolTemplateCommand extends Command
{
    protected $signature = 'ikontroladmin:build-template {--source=} {--version=} {--approved-sql=} {--classification=}';
    protected $description = 'Construye un par ZIP + SQL desde una fuente limpia explícita y un dump previamente aprobado';

    public function handle(): int
    {
        try {
            [$source, $sql, $version, $classification] = $this->validateInputs();
            $target = rtrim((string) config('ikontrol.templates.root'), '/\\').DIRECTORY_SEPARATOR.$version;
            if (File::exists($target)) throw new RuntimeException('La carpeta de esa versión ya existe; no se sobrescribe.');
            File::ensureDirectoryExists(dirname($target), 0750);
            $temporary = storage_path('app/template-build-'.bin2hex(random_bytes(8)));
            File::ensureDirectoryExists($temporary, 0700);
            try {
                $stage = $temporary.DIRECTORY_SEPARATOR.'source'; File::ensureDirectoryExists($stage, 0700);
                $this->stageSource($source, $stage);
                foreach (['index.php', 'spark', '.env.example'] as $required) if (! is_file($stage.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $required))) throw new RuntimeException("La fuente no contiene {$required}.");
                foreach (['app', 'system'] as $required) if (! is_dir($stage.DIRECTORY_SEPARATOR.$required)) throw new RuntimeException("La fuente no contiene {$required}/.");
                File::ensureDirectoryExists($target, 0750);
                $zipPath = $target.DIRECTORY_SEPARATOR."ikontrol-{$version}.zip";
                $this->zip($stage, $zipPath);
                $sqlPath = $target.DIRECTORY_SEPARATOR."ikontrol-{$version}.sql";
                File::copy($sql, $sqlPath);
                File::put($target.DIRECTORY_SEPARATOR.'table-classification.json', json_encode($classification, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                File::put($target.DIRECTORY_SEPARATOR.'checksums.sha256', hash_file('sha256', $zipPath).'  '.basename($zipPath).PHP_EOL.hash_file('sha256', $sqlPath).'  '.basename($sqlPath).PHP_EOL);
                $this->info("Plantilla construida en {$target}");
                $this->line('ZIP SHA-256: '.hash_file('sha256', $zipPath));
                $this->line('SQL SHA-256: '.hash_file('sha256', $sqlPath));
            } finally { File::deleteDirectory($temporary); }
            return self::SUCCESS;
        } catch (\Throwable $e) { $this->error($e->getMessage()); return self::FAILURE; }
    }

    private function validateInputs(): array
    {
        $source = realpath((string) $this->option('source')); $sql = realpath((string) $this->option('approved-sql'));
        $classificationPath = realpath((string) $this->option('classification')); $version = (string) $this->option('version');
        $registeredPaths = IkontrolInstance::query()->whereNotNull('absolute_path')->pluck('absolute_path')->map(fn ($path) => realpath($path))->filter();
        $customerFolder = $source !== false && str_ends_with(strtolower(basename($source)), strtolower((string) config('ikontrol.folder_suffix')));
        if ($source === false || ! is_dir($source) || $customerFolder || $registeredPaths->contains($source)) throw new RuntimeException('La fuente debe ser explícita y no puede ser una instalación de cliente registrada.');
        if (! preg_match('/\A\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?\z/', $version)) throw new RuntimeException('Versión inválida.');
        if ($sql === false || ! is_file($sql) || filesize($sql) < 1) throw new RuntimeException('Debe proporcionar un SQL aprobado no vacío.');
        if ($classificationPath === false || ! is_file($classificationPath)) throw new RuntimeException('Debe proporcionar la clasificación explícita de tablas.');
        $classification = json_decode((string) File::get($classificationPath), true, flags: JSON_THROW_ON_ERROR);
        $keys = ['STRUCTURE_ONLY', 'KEEP_DATA', 'EMPTY_DATA']; $all = [];
        foreach ($keys as $key) { if (! isset($classification[$key]) || ! is_array($classification[$key])) throw new RuntimeException("Falta clasificación {$key}."); foreach ($classification[$key] as $table) { if (! is_string($table) || ! preg_match('/\A[a-zA-Z0-9_]+\z/', $table) || in_array($table, $all, true)) throw new RuntimeException('La whitelist contiene una tabla inválida o duplicada.'); $all[] = $table; } }
        if ($all === []) throw new RuntimeException('La clasificación está vacía; audite el schema antes de construir.');
        return [$source, $sql, $version, $classification];
    }

    private function stageSource(string $source, string $stage): void
    {
        $excluded = ['.env', '.git', 'node_modules', 'writable', 'backups', 'logs', 'storage/logs', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'public/uploads'];
        foreach (File::allFiles($source) as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            $segments = explode('/', strtolower($relative));
            if ($file->isLink() || preg_match('/\.(?:cer|key|pfx|p12|pem)$/i', $relative) || in_array('.env', $segments, true) || in_array('.git', $segments, true) || in_array('node_modules', $segments, true) || collect($excluded)->contains(fn ($path) => $relative === $path || str_starts_with($relative, $path.'/'))) continue;
            $destination = $stage.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative); File::ensureDirectoryExists(dirname($destination), 0700); File::copy($file->getPathname(), $destination);
        }
    }

    private function zip(string $source, string $target): void
    {
        $zip = new ZipArchive(); if ($zip->open($target, ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new RuntimeException('No fue posible crear el ZIP.');
        foreach (File::allFiles($source) as $file) $zip->addFile($file->getPathname(), str_replace('\\', '/', $file->getRelativePathname()));
        if (! $zip->close()) throw new RuntimeException('No fue posible finalizar el ZIP.');
    }
}
