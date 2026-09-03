<?php

namespace App\Services\VersionSources;

use App\Models\IkontrolVersion;
use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

class ArchiveVersionSource implements VersionSource
{
    public function materialize(IkontrolVersion $version, string $destination): void
    {
        $root = realpath((string) config('ikontrol.version_sources.archive_root'));
        $reference = trim($version->source_reference);
        $archive = $root !== false ? realpath($root.DIRECTORY_SEPARATOR.$reference) : false;
        if ($root === false || $archive === false || ! str_starts_with($archive, $root.DIRECTORY_SEPARATOR) || ! is_file($archive)) {
            throw new RuntimeException('La fuente archive no está disponible dentro de la raíz configurada.');
        }
        if ($version->checksum !== null && ! hash_equals(strtolower($version->checksum), hash_file('sha256', $archive))) {
            throw new RuntimeException('El checksum de la versión no coincide.');
        }
        if (! File::isDirectory($destination) && ! File::makeDirectory($destination, 0750, true)) {
            throw new RuntimeException('No fue posible preparar el destino del código.');
        }
        $zip = new ZipArchive();
        if ($zip->open($archive) !== true) {
            throw new RuntimeException('No fue posible abrir el archivo de la versión.');
        }
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $zip->getNameIndex($index);
            $normalized = str_replace('\\', '/', $entry);
            $zip->getExternalAttributesIndex($index, $opsys, $attributes);
            $isSymlink = $opsys === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000;
            if ($normalized === '' || $isSymlink || str_contains($normalized, '../') || str_starts_with($normalized, '/') || str_contains($normalized, ':')) {
                $zip->close();
                throw new RuntimeException('El archivo contiene una ruta insegura.');
            }
        }
        if (! $zip->extractTo($destination)) {
            $zip->close();
            throw new RuntimeException('No fue posible extraer la versión.');
        }
        $zip->close();
    }
}
