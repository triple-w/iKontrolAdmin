<?php

namespace App\Services;

use App\Enums\InstallationStatus as S;
use App\Models\{Client, IkontrolInstance, IkontrolTemplate, IkontrolVersion, InstanceInstallationLog};
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class InstanceProvisioningService
{
    public function __construct(private CpanelService $cpanel, private InstanceFilesystemService $fs, private IkontrolInstanceConnectionService $connection, private AuditService $audit, private ?IkontrolDeploymentService $deployment = null, private ?IkontrolTemplateValidationService $templateValidator = null, private ?IkontrolDatabaseTemplateService $databaseTemplate = null) {}

    public function databaseName(string $slug): string
    {
        if (! $this->fs->validateSlug($slug)) throw new InvalidArgumentException('Slug inválido.');
        $name = config('ikontrol.db.prefix').$slug;
        if (strlen($name) > 64) throw new InvalidArgumentException('El nombre de base excede 64 caracteres.');
        return $name;
    }

    public function preview(string $slug, IkontrolVersion|IkontrolTemplate|null $source = null): array
    {
        return ['folder_name' => $this->fs->folderName($slug), 'absolute_path' => $this->fs->path($slug), 'db_name' => $this->databaseName($slug), 'domain' => 'https://'.$slug.'.ikontrol.solutions', 'version' => $source?->version, 'app_version' => $source instanceof IkontrolTemplate ? $source->app_version : null, 'schema_version' => $source instanceof IkontrolTemplate ? $source->schema_version : null];
    }

    public function preflight(string $slug, IkontrolVersion|IkontrolTemplate|null $source = null): array
    {
        $preview = $this->preview($slug, $source);
        $root = rtrim((string) config('ikontrol.instances_root'), '/\\').DIRECTORY_SEPARATOR;
        $checks = ['slug_valid' => $this->fs->validateSlug($slug), 'folder_available' => ! $this->fs->folderExists($slug), 'filesystem_writable' => $this->fs->rootWritable(), 'cpanel_connected' => false, 'database_not_existing' => false, 'mysql_user_available' => false];
        if ($source !== null) $checks = ['slug_valid' => $checks['slug_valid'], 'path_safe' => str_starts_with($preview['absolute_path'], $root) && ! str_contains($slug, '..')] + $checks + [$source instanceof IkontrolTemplate ? 'template_valid' : 'version_available' => $this->sourceAvailable($source)];
        try { $databases = $this->cpanel->listDatabases(); $checks['cpanel_connected'] = true; $checks['database_not_existing'] = ! in_array($preview['db_name'], $databases, true); } catch (Throwable) {}
        $checks['mysql_user_available'] = $this->connection->testGlobalConnection()['success'];
        return $preview+['checks' => $checks, 'success' => ! in_array(false, $checks, true)];
    }

    public function dryRun(string $slug, IkontrolVersion|IkontrolTemplate|null $source = null): array { return ['dry_run' => true]+$this->preflight($slug, $source); }

    public function provision(array $data): IkontrolInstance
    {
        if (! empty($data['ikontrol_template_id'])) return $this->provisionTemplate($data);
        $version = IkontrolVersion::whereKey($data['ikontrol_version_id'] ?? null)->where('active', true)->firstOrFail();
        $client = ($data['client_mode'] ?? null) === 'existing' ? Client::findOrFail($data['client_id']) : Client::create(['name' => $data['new_client_name'], 'active' => true]);
        $preview = $this->preview($data['slug'], $version);
        $instance = IkontrolInstance::create(['client_id' => $client->id, 'ikontrol_version_id' => $version->id, 'name' => $data['name'], 'slug' => $data['slug'], 'folder_name' => $preview['folder_name'], 'absolute_path' => $preview['absolute_path'], 'domain' => $data['domain'] ?? null, 'url' => $preview['domain'], 'db_host' => config('ikontrol.db.host'), 'db_port' => config('ikontrol.db.port'), 'db_name' => $preview['db_name'], 'installation_status' => S::Pending]);
        return $this->run($instance, $version, 0);
    }

    public function retry(IkontrolInstance $instance): IkontrolInstance
    {
        if ($instance->template) {
            $steps = $this->templateSteps($instance, $instance->template);
            $failed = $instance->installationLogs()->where('status', 'FAILED')->latest('id')->value('step');
            $start = array_search($failed, array_map(fn ($step) => $step[0]->value, $steps), true);
            return $this->runTemplate($instance, $instance->template, $start === false ? 0 : $start);
        }
        $version = $instance->version;
        if (! $version) throw new RuntimeException('La instalación no tiene una versión asignada.');
        $failed = $instance->installationLogs()->where('status', 'FAILED')->latest('id')->value('step');
        $steps = $this->steps($instance, $version);
        $start = array_search($failed, array_map(fn ($step) => $step[0]->value, $steps), true);
        return $this->run($instance, $version, $start === false ? 0 : $start);
    }

    public function confirmDomain(IkontrolInstance $instance): IkontrolInstance
    {
        if ($instance->installation_status !== S::ReadyForDomain) throw new RuntimeException('La instalación aún no está preparada para confirmar dominio.');
        $url = 'https://'.$instance->slug.'.ikontrol.solutions';
        if ($instance->url && $instance->url !== $url) throw new RuntimeException('La URL de la instalación no coincide con el dominio esperado.');
        $response = \Illuminate\Support\Facades\Http::withoutRedirecting()->timeout(15)->get($url);
        if (! $response->successful()) throw new RuntimeException('El dominio no respondió correctamente.');
        $instance->update(['url' => $url, 'installation_status' => S::Ready]);
        $this->log($instance, S::Ready, 'SUCCESS', 'Dominio confirmado y aplicación accesible.');
        return $instance->fresh();
    }

    private function run(IkontrolInstance $instance, IkontrolVersion $version, int $start): IkontrolInstance
    {
        try {
            foreach (array_slice($this->steps($instance, $version), $start) as [$status, $action]) $this->step($instance, $status, $action);
            $instance->update(['installation_status' => S::ReadyForDomain, 'installed_version' => $version->version, 'installed_at' => now()]);
            $this->log($instance, S::ReadyForDomain, 'SUCCESS', 'Instalación preparada; falta confirmar el dominio.');
            $this->audit->record('provision_instance', 'Instalación preparada para dominio.', $instance);
        } catch (Throwable $e) {
            $failed = $instance->installation_status;
            $instance->update(['installation_status' => S::Failed]);
            $this->log($instance, $failed, 'FAILED', $this->safeMessage($e));
            report($e);
        }
        return $instance->fresh();
    }

    private function steps(IkontrolInstance $instance, IkontrolVersion $version): array
    {
        $deployment = $this->deployment ?? app(IkontrolDeploymentService::class);
        return [
            [S::Validating, fn () => throw_if(! $this->preflight($instance->slug, $version)['success'], new RuntimeException('Preflight no superado.'))],
            [S::CreatingFolder, fn () => $this->fs->folderExists($instance->slug) ?: $this->fs->createFolder($instance->slug)],
            [S::CreatingDatabase, fn () => $this->cpanel->databaseExists($instance->db_name) ?: $this->cpanel->createDatabase($instance->db_name)],
            [S::AssigningDatabaseUser, fn () => $this->cpanel->assignUserToDatabase($instance->db_name)],
            [S::DeployingCode, fn () => $deployment->deployCode($instance, $version)],
            [S::CreatingEnv, fn () => $deployment->createEnvironment($instance)],
            [S::InstallingDependencies, fn () => $deployment->verifyDependencies($instance)],
            [S::GeneratingKey, fn () => $this->command($deployment, $instance, 'key:generate', ['--force'])],
            [S::RunningMigrations, fn () => $this->command($deployment, $instance, 'migrate', ['--force'])],
            [S::Optimizing, fn () => $this->optimize($deployment, $instance)],
            [S::TestingConnection, function () use ($deployment, $instance) { throw_if(! $this->connection->test($instance)['success'], new RuntimeException('La conexión de la instalación falló.')); $this->command($deployment, $instance, 'about', ['--only=environment,cache,drivers']); }],
        ];
    }

    private function provisionTemplate(array $data): IkontrolInstance
    {
        $template = IkontrolTemplate::whereKey($data['ikontrol_template_id'])->where('active', true)->firstOrFail();
        $client = ($data['client_mode'] ?? null) === 'existing' ? Client::findOrFail($data['client_id']) : Client::create(['name' => $data['new_client_name'], 'active' => true]);
        $preview = $this->preview($data['slug'], $template);
        $instance = IkontrolInstance::create(['client_id' => $client->id, 'ikontrol_template_id' => $template->id, 'name' => $data['name'], 'slug' => $data['slug'], 'folder_name' => $preview['folder_name'], 'absolute_path' => $preview['absolute_path'], 'url' => $preview['domain'], 'db_host' => config('ikontrol.db.host'), 'db_port' => config('ikontrol.db.port'), 'db_name' => $preview['db_name'], 'app_version' => $template->app_version, 'schema_version' => $template->schema_version, 'installation_status' => S::Pending]);
        return $this->runTemplate($instance, $template, 0);
    }

    private function runTemplate(IkontrolInstance $instance, IkontrolTemplate $template, int $start): IkontrolInstance
    {
        try {
            foreach (array_slice($this->templateSteps($instance, $template), $start) as [$status, $action]) $this->step($instance, $status, $action);
            $instance->update(['installation_status' => S::ReadyForDomain, 'installed_version' => $template->app_version, 'installed_at' => now()]);
            $this->log($instance, S::ReadyForDomain, 'SUCCESS', 'Instalación preparada; falta confirmar el dominio.');
            $this->audit->record('provision_instance', 'Instalación preparada desde plantilla para dominio.', $instance);
        } catch (Throwable $e) {
            $failed = $instance->installation_status; $instance->update(['installation_status' => S::Failed]);
            $this->log($instance, $failed, 'FAILED', $this->safeMessage($e)); report($e);
        }
        return $instance->fresh();
    }

    private function templateSteps(IkontrolInstance $instance, IkontrolTemplate $template): array
    {
        $deployment = $this->deployment ?? app(IkontrolDeploymentService::class);
        $database = $this->databaseTemplate ?? app(IkontrolDatabaseTemplateService::class);
        return [
            [S::ValidatingTemplate, fn () => throw_if(! $this->preflight($instance->slug, $template)['success'], new RuntimeException('Preflight de plantilla no superado.'))],
            [S::CreatingFolder, fn () => $this->fs->folderExists($instance->slug) ?: $this->fs->createFolder($instance->slug)],
            [S::CreatingDatabase, fn () => $this->cpanel->databaseExists($instance->db_name) ?: $this->cpanel->createDatabase($instance->db_name)],
            [S::AssigningDatabaseUser, fn () => $this->cpanel->assignUserToDatabase($instance->db_name)],
            [S::DeployingFiles, fn () => $deployment->deployTemplate($instance, $template)],
            [S::ImportingDatabaseTemplate, fn () => $database->import($instance, $template)],
            [S::CreatingEnv, fn () => $deployment->createEnvironment($instance)],
            [S::GeneratingAppKey, fn () => $this->command($deployment, $instance, 'key:generate', ['--force'])],
            [S::Optimizing, fn () => $this->optimize($deployment, $instance)],
            [S::TestingConnection, fn () => throw_if(! $this->connection->test($instance)['success'], new RuntimeException('La conexión de la instalación falló.'))],
        ];
    }

    private function sourceAvailable(IkontrolVersion|IkontrolTemplate $source): bool
    {
        if (! $source->active) return false;
        if ($source instanceof IkontrolVersion) return true;
        try { ($this->templateValidator ?? app(IkontrolTemplateValidationService::class))->validate($source); return true; } catch (Throwable) { return false; }
    }

    private function command(IkontrolDeploymentService $deployment, IkontrolInstance $instance, string $command, array $arguments): void
    {
        $result = $deployment->run($instance, $command, $arguments);
        if (($result['exit_code'] ?? 1) !== 0) throw new RuntimeException('Artisan falló: '.$result['output']);
    }

    private function optimize(IkontrolDeploymentService $deployment, IkontrolInstance $instance): void
    {
        foreach ([['optimize:clear', []], ['config:cache', []], ['route:cache', []], ['view:cache', []]] as [$command, $arguments]) $this->command($deployment, $instance, $command, $arguments);
    }

    private function step(IkontrolInstance $instance, S $status, callable $action): void
    {
        $instance->update(['installation_status' => $status]); $this->log($instance, $status, 'STARTED', 'Paso iniciado.'); $action(); $this->log($instance, $status, 'SUCCESS', 'Paso completado.');
    }

    private function log(IkontrolInstance $instance, S|string $step, string $status, string $message): void
    {
        foreach ([(string) config('ikontrol.db.password'), (string) config('ikontrol.cpanel.token')] as $secret) if ($secret !== '') $message = str_replace($secret, '[REDACTED]', $message);
        $message = preg_replace('/(password|token|secret)\s*[=:]\s*[^\s]+/i', '$1=[REDACTED]', $message);
        InstanceInstallationLog::create(['instance_id' => $instance->id, 'step' => $step instanceof S ? $step->value : $step, 'status' => $status, 'message' => mb_substr($message, 0, 2000), 'created_at' => now()]);
    }

    private function safeMessage(Throwable $exception): string
    {
        $message = $exception->getMessage();
        foreach ([(string) config('ikontrol.db.password'), (string) config('ikontrol.cpanel.token')] as $secret) if ($secret !== '') $message = str_replace($secret, '[REDACTED]', $message);
        $message = preg_replace('/(password|token|secret)\s*[=:]\s*[^\s]+/i', '$1=[REDACTED]', $message);
        return mb_substr($message ?: 'Falló el provisioning.', 0, 500);
    }
}
