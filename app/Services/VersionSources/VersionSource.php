<?php

namespace App\Services\VersionSources;

use App\Models\IkontrolVersion;

interface VersionSource
{
    public function materialize(IkontrolVersion $version, string $destination): void;
}
