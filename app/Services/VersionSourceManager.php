<?php

namespace App\Services;

use App\Models\IkontrolVersion;
use App\Services\VersionSources\ArchiveVersionSource;
use RuntimeException;

class VersionSourceManager
{
    public function materialize(IkontrolVersion $version, string $destination): void
    {
        match ($version->source_type) {
            'archive' => (new ArchiveVersionSource())->materialize($version, $destination),
            'git' => throw new RuntimeException('La fuente git está reservada para una futura implementación segura.'),
            default => throw new RuntimeException('Tipo de fuente de versión no permitido.'),
        };
    }
}
