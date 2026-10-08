<?php

namespace App\Console\Commands;

use App\Services\Versioning\ReleaseSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncIkontrolReleasesCommand extends Command
{
    protected $signature = 'ikontrol:sync-releases {--json : Devuelve el resumen como JSON}';
    protected $description = 'Descubre y registra releases distribuibles de ikontrol-platform';

    public function handle(ReleaseSyncService $sync): int
    {
        try {
            $summary = $sync->sync();
            if ($this->option('json')) {
                $this->line(json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } else {
                $this->line('Discovered: '.$summary['discovered']);
                $this->line('Imported: '.$summary['imported']);
                $this->line('Updated: '.$summary['updated']);
                $this->line('Invalid: '.$summary['invalid']);
            }
            return self::SUCCESS;
        } catch (Throwable) {
            $message = 'No fue posible sincronizar releases. Revise conectividad y configuración de GitHub.';
            if ($this->option('json')) $this->line(json_encode(['error' => $message], JSON_THROW_ON_ERROR));
            else $this->error($message);
            return self::FAILURE;
        }
    }
}
