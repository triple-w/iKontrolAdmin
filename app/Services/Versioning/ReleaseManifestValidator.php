<?php

namespace App\Services\Versioning;

use InvalidArgumentException;
use JsonException;

class ReleaseManifestValidator
{
    private const VERSION_PATTERN = '/\A\d+\.\d+\.\d+(?:-[0-9A-Za-z]+(?:\.[0-9A-Za-z]+)*)?(?:\+[0-9A-Za-z]+(?:\.[0-9A-Za-z]+)*)?\z/';
    private const REQUIRED = ['schema_version', 'product', 'version', 'channel', 'from_versions', 'migrations', 'commands', 'health_checks', 'requires_backup'];

    public function parse(string $json): array
    {
        if ($json === '' || strlen($json) > (int) config('ikontrol.releases.max_manifest_bytes', 262144)) {
            throw new InvalidArgumentException('El manifest está vacío o excede el tamaño permitido.');
        }

        try {
            $manifest = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('El manifest no contiene JSON válido.');
        }

        if (! is_array($manifest) || array_is_list($manifest)) {
            throw new InvalidArgumentException('El manifest debe ser un objeto JSON.');
        }

        $missing = array_diff(self::REQUIRED, array_keys($manifest));
        $unknown = array_diff(array_keys($manifest), self::REQUIRED);
        if ($missing !== []) throw new InvalidArgumentException('Faltan campos requeridos: '.implode(', ', $missing).'.');
        if ($unknown !== []) throw new InvalidArgumentException('El manifest contiene campos no permitidos: '.implode(', ', $unknown).'.');
        $this->rejectSecrets($manifest);

        if ($manifest['schema_version'] !== 1) throw new InvalidArgumentException('schema_version debe ser 1.');
        if ($manifest['product'] !== 'ikontrol') throw new InvalidArgumentException('product debe ser ikontrol.');
        $this->assertVersion($manifest['version'], 'version');
        if (! in_array($manifest['channel'], ['canary', 'stable'], true)) throw new InvalidArgumentException('channel debe ser canary o stable.');
        if ($manifest['channel'] === 'stable' && str_contains($manifest['version'], '-')) throw new InvalidArgumentException('Una versión prerelease no puede publicarse en stable.');
        if (! is_bool($manifest['requires_backup'])) throw new InvalidArgumentException('requires_backup debe ser booleano.');

        $this->assertList($manifest['from_versions'], 'from_versions');
        foreach ($manifest['from_versions'] as $version) $this->assertVersion($version, 'from_versions');
        if (count($manifest['from_versions']) !== count(array_unique($manifest['from_versions']))) throw new InvalidArgumentException('from_versions contiene duplicados.');

        $this->assertList($manifest['migrations'], 'migrations');
        $migrationIds = [];
        foreach ($manifest['migrations'] as $migration) {
            if (! is_array($migration) || array_is_list($migration) || count(array_diff(array_keys($migration), ['id', 'required'])) > 0 || ! array_key_exists('id', $migration) || ! array_key_exists('required', $migration)) throw new InvalidArgumentException('Cada migration debe contener únicamente id y required.');
            if (! is_string($migration['id']) || ! preg_match('/\A[0-9A-Za-z][0-9A-Za-z_-]{0,199}\z/', $migration['id']) || ! is_bool($migration['required'])) throw new InvalidArgumentException('Migration inválida.');
            if (in_array($migration['id'], $migrationIds, true)) throw new InvalidArgumentException('migrations contiene IDs duplicados.');
            $migrationIds[] = $migration['id'];
        }

        foreach (['commands', 'health_checks'] as $field) {
            $this->assertList($manifest[$field], $field);
            foreach ($manifest[$field] as $command) {
                if (! is_string($command) || ! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9:_-]{0,99}\z/', $command)) throw new InvalidArgumentException("{$field} contiene un comando inválido.");
            }
        }

        return $manifest;
    }

    public function versionFromTag(string $tag): string
    {
        if (! preg_match('/\Av?(\d+\.\d+\.\d+(?:-[0-9A-Za-z]+(?:\.[0-9A-Za-z]+)*)?(?:\+[0-9A-Za-z]+(?:\.[0-9A-Za-z]+)*)?)\z/', $tag, $matches)) {
            throw new InvalidArgumentException('El tag no representa una versión semántica válida.');
        }
        return $matches[1];
    }

    private function assertVersion(mixed $version, string $field): void
    {
        if (! is_string($version) || strlen($version) > 80 || ! preg_match(self::VERSION_PATTERN, $version)) throw new InvalidArgumentException("{$field} contiene una versión semántica inválida.");
    }

    private function assertList(mixed $value, string $field): void
    {
        if (! is_array($value) || ! array_is_list($value)) throw new InvalidArgumentException("{$field} debe ser una lista.");
    }

    private function rejectSecrets(array $data): void
    {
        foreach ($data as $key => $value) {
            if (preg_match('/password|passwd|secret|token|credential|private[_-]?key|api[_-]?key/i', (string) $key)) throw new InvalidArgumentException('El manifest no puede contener secretos.');
            if (is_array($value)) $this->rejectSecrets($value);
        }
    }
}
