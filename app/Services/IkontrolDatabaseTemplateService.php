<?php

namespace App\Services;

use App\Models\{IkontrolInstance, IkontrolTemplate};
use RuntimeException;
use Symfony\Component\Process\Process;

class IkontrolDatabaseTemplateService
{
    public function __construct(private IkontrolTemplateValidationService $validator) {}

    public function import(IkontrolInstance $instance, IkontrolTemplate $template): array
    {
        $validated = $this->validator->validate($template);
        if ($instance->ikontrol_template_id !== $template->id) throw new RuntimeException('La plantilla no corresponde a la instalación.');
        if (! preg_match('/\A[a-zA-Z0-9_]+\z/', $instance->db_name)) throw new RuntimeException('Nombre de base inválido.');

        $command = [
            (string) config('ikontrol.templates.mysql_binary', 'mysql'),
            '--protocol=TCP', '--host='.(string) config('ikontrol.db.host'), '--port='.(int) config('ikontrol.db.port'),
            '--user='.(string) config('ikontrol.db.username'), '--database='.$instance->db_name,
            '--default-character-set=utf8mb4',
        ];
        $started = microtime(true);
        $process = new Process($command, null, ['MYSQL_PWD' => (string) config('ikontrol.db.password')]);
        $process->setTimeout((int) config('ikontrol.deployment.command_timeout', 300));
        $process->setInput(fopen($validated['database'], 'rb'));
        $process->run();
        $output = $this->sanitize($process->getOutput().$process->getErrorOutput());
        if (! $process->isSuccessful()) throw new RuntimeException('Falló la importación SQL: '.$output);
        return ['exit_code' => 0, 'duration_ms' => (int) ((microtime(true) - $started) * 1000), 'output' => $output];
    }

    private function sanitize(string $output): string
    {
        foreach ([(string) config('ikontrol.db.password'), (string) config('ikontrol.cpanel.token')] as $secret) {
            if ($secret !== '') $output = str_replace($secret, '[REDACTED]', $output);
        }
        return mb_substr(trim($output), 0, 2000);
    }
}
