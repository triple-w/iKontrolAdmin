<?php

namespace App\Services;

use App\Models\IkontrolTemplate;
use RuntimeException;
use ZipArchive;

class IkontrolTemplateValidationService
{
    public function validate(IkontrolTemplate $template): array
    {
        if (! preg_match('/\A\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?\z/', $template->version)
            || trim($template->app_version) === '' || trim($template->schema_version) === '') {
            throw new RuntimeException('La versión o schema declarados no son válidos.');
        }

        $archive = $this->resolve($template, $template->archive_path, 'ZIP');
        $database = $this->resolve($template, $template->database_dump_path, 'SQL');
        $this->checksum($archive, $template->archive_sha256, 'ZIP');
        $this->checksum($database, $template->database_sha256, 'SQL');
        if (filesize($database) < 1) throw new RuntimeException('El SQL de la plantilla está vacío.');
        $sql = (string) file_get_contents($database);
        if (preg_match('/\b(?:CREATE|DROP)\s+DATABASE\b|\bUSE\s+[`"\[]?|\bGRANT\b|\bINTO\s+OUTFILE\b|\bLOAD\s+DATA\b/i', $sql)) {
            throw new RuntimeException('El SQL contiene operaciones fuera de la base asignada.');
        }
        $this->validateArchive($archive);

        return ['archive' => $archive, 'database' => $database, 'archive_sha256' => hash_file('sha256', $archive), 'database_sha256' => hash_file('sha256', $database)];
    }

    public function resolve(IkontrolTemplate $template, string $relative, string $type): string
    {
        $root = realpath((string) config('ikontrol.templates.root'));
        if ($root === false || $relative === '' || str_contains($relative, '..') || preg_match('#^(?:[A-Za-z]:|[\\/])#', $relative)) {
            throw new RuntimeException("La ruta {$type} de la plantilla no es segura.");
        }
        $expectedPrefix = str_replace('\\', '/', $template->version).'/';
        $normalized = str_replace('\\', '/', $relative);
        $path = realpath($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $normalized));
        if (! str_starts_with($normalized, $expectedPrefix) || $path === false || ! is_file($path) || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("El archivo {$type} no existe en la carpeta versionada esperada.");
        }
        return $path;
    }

    private function checksum(string $path, string $expected, string $type): void
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/i', $expected) || ! hash_equals(strtolower($expected), hash_file('sha256', $path))) {
            throw new RuntimeException("El checksum {$type} no coincide.");
        }
    }

    private function validateArchive(string $archive): void
    {
        $zip = new ZipArchive();
        if ($zip->open($archive) !== true) throw new RuntimeException('No fue posible abrir el ZIP de la plantilla.');
        $files = [];
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = str_replace('\\', '/', (string) $zip->getNameIndex($i));
                $trimmed = rtrim($entry, '/');
                $files[] = $trimmed;
                $zip->getExternalAttributesIndex($i, $opsys, $attributes);
                $symlink = $opsys === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000;
                $segments = explode('/', strtolower($trimmed));
                if ($entry === '' || $symlink || str_contains($entry, '../') || str_starts_with($entry, '/') || str_contains($entry, ':')) {
                    throw new RuntimeException('El ZIP contiene una ruta o symlink peligroso.');
                }
                $sensitiveCertificate = (bool) preg_match('/\.(?:cer|key|pfx|p12|pem)$/i', $trimmed);
                if (in_array('.env', $segments, true) || in_array('.git', $segments, true) || in_array('node_modules', $segments, true)
                    || in_array('writable', $segments, true) || in_array('backups', $segments, true) || in_array('logs', $segments, true)
                    || $sensitiveCertificate) {
                    throw new RuntimeException('El ZIP contiene archivos o directorios excluidos.');
                }
            }
            foreach (['index.php', 'spark', '.env.example'] as $required) {
                if (! in_array($required, $files, true)) throw new RuntimeException("El ZIP no contiene {$required}.");
            }
            foreach (['app/', 'system/'] as $requiredDirectory) {
                if (! collect($files)->contains(fn ($entry) => str_starts_with($entry.'/', $requiredDirectory))) throw new RuntimeException("El ZIP no contiene {$requiredDirectory}.");
            }
        } finally {
            $zip->close();
        }
    }
}
