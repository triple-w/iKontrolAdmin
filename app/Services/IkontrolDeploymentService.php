<?php

namespace App\Services;

use App\Models\{IkontrolInstance, IkontrolTemplate, IkontrolVersion};
use Illuminate\Support\Facades\File;
use RuntimeException;

class IkontrolDeploymentService
{
    public function __construct(private VersionSourceManager $sources, private AllowedArtisanRunner $artisan, private ?IkontrolTemplateValidationService $templates = null, private ?AllowedSparkRunner $spark = null) {}

    public function deployTemplate(IkontrolInstance $instance, IkontrolTemplate $template): void
    {
        $path = $this->safeInstancePath($instance);
        $marker = $path.DIRECTORY_SEPARATOR.'.ikontrol-template.json';
        if ($instance->ikontrol_template_id !== $template->id) throw new RuntimeException('La plantilla no corresponde a la instalación.');
        if (File::exists($marker)) {
            $metadata = json_decode((string) File::get($marker), true);
            if (($metadata['template_id'] ?? null) !== $template->id || ($metadata['archive_sha256'] ?? null) !== $template->archive_sha256) {
                throw new RuntimeException('La carpeta contiene otra plantilla iKontrol.');
            }
            if (($metadata['status'] ?? null) === 'READY' && is_file($path.DIRECTORY_SEPARATOR.'artisan')) return;
        } elseif (File::exists($path) && count(File::allFiles($path)) > 0) {
            throw new RuntimeException('La carpeta de la instalación ya está ocupada.');
        }
        $validated = ($this->templates ?? app(IkontrolTemplateValidationService::class))->validate($template);
        File::ensureDirectoryExists($path, 0750);
        File::put($marker, json_encode(['template_id' => $template->id, 'archive_sha256' => $template->archive_sha256, 'status' => 'DEPLOYING'], JSON_THROW_ON_ERROR));
        $zip = new \ZipArchive();
        if ($zip->open($validated['archive']) !== true || ! $zip->extractTo($path)) throw new RuntimeException('No fue posible extraer la plantilla.');
        $zip->close();
        if (! is_file($path.DIRECTORY_SEPARATOR.'index.php') || ! is_file($path.DIRECTORY_SEPARATOR.'spark') || ! is_dir($path.DIRECTORY_SEPARATOR.'app') || ! is_dir($path.DIRECTORY_SEPARATOR.'system')) {
            throw new RuntimeException('La plantilla no contiene una aplicación iKontrol válida.');
        }
        foreach (['writable', 'writable/cache', 'writable/logs', 'writable/session', 'writable/uploads'] as $directory) {
            File::ensureDirectoryExists($path.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $directory), 0775);
        }
        File::put($marker, json_encode(['template_id' => $template->id, 'archive_sha256' => $template->archive_sha256, 'status' => 'READY'], JSON_THROW_ON_ERROR));
    }

    public function deployCode(IkontrolInstance $instance, IkontrolVersion $version): void
    {
        $path = $this->safeInstancePath($instance);
        $marker = $path.DIRECTORY_SEPARATOR.'.ikontrol-deployment.json';
        if (File::exists($marker)) {
            $metadata = json_decode((string) File::get($marker), true);
            if (($metadata['version_id'] ?? null) !== $version->id || ($metadata['source_reference'] ?? null) !== $version->source_reference) {
                throw new RuntimeException('La carpeta contiene otra versión de iKontrol.');
            }
            if (($metadata['status'] ?? null) === 'READY' && is_file($path.DIRECTORY_SEPARATOR.'artisan')) return;
        } elseif (File::exists($path) && count(File::allFiles($path)) > 0) {
            throw new RuntimeException('La carpeta de la instalación ya está ocupada.');
        }
        File::ensureDirectoryExists($path, 0750);
        File::put($marker, json_encode(['version_id' => $version->id, 'source_reference' => $version->source_reference, 'status' => 'DEPLOYING'], JSON_THROW_ON_ERROR));
        $this->sources->materialize($version, $path);
        if (! is_file($path.DIRECTORY_SEPARATOR.'artisan') || ! is_file($path.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'index.php')) {
            throw new RuntimeException('La fuente no contiene una aplicación iKontrol válida.');
        }
        foreach (['storage', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'] as $directory) {
            File::ensureDirectoryExists($path.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $directory), 0775);
        }
        File::put($marker, json_encode(['version_id' => $version->id, 'source_reference' => $version->source_reference, 'status' => 'READY'], JSON_THROW_ON_ERROR));
    }

    public function createEnvironment(IkontrolInstance $instance): void
    {
        $path = $this->safeInstancePath($instance);
        $target = $path.DIRECTORY_SEPARATOR.'.env';
        if (File::exists($target)) {
            $existing = (string) File::get($target);
            if (
                str_contains($existing, 'APP_ENV=production')
                && str_contains($existing, 'DB_DATABASE='.$this->envValue($instance->db_name))
            ) {
                return;
            }
            throw new RuntimeException('Ya existe un .env que no corresponde a esta instalación.');
        }
        $env = implode(PHP_EOL, [
            'APP_ENV=production',
            'APP_DEBUG=false',
            'APP_URL='.$this->envValue($instance->url ?: 'https://'.$instance->slug.'.ikontrol.solutions'),
            'APP_KEY=',
            '',
            'DB_CONNECTION=mysql',
            'DB_HOST='.$this->envValue($instance->db_host ?: config('ikontrol.db.host')),
            'DB_PORT='.($instance->db_port ?: config('ikontrol.db.port')),
            'DB_DATABASE='.$this->envValue($instance->db_name),
            'DB_USERNAME='.$this->envValue(config('ikontrol.db.username')),
            'DB_PASSWORD='.$this->envValue(config('ikontrol.db.password')),
            '',
        ]).PHP_EOL;
        $temporary = $target.'.tmp';
        if (File::put($temporary, $env) === false || ! File::move($temporary, $target)) {
            throw new RuntimeException('No fue posible generar el .env de la instalación.');
        }
        @chmod($target, 0600);
    }

    public function createTemplateEnvironment(IkontrolInstance $instance): array
    {
        if (! $instance->template) throw new RuntimeException('La instalación no pertenece a una plantilla iKontrol.');
        $path = $this->safeInstancePath($instance); $target = $path.DIRECTORY_SEPARATOR.'.env';
        $existing = File::exists($target) ? (string) File::get($target) : '';
        $encryptionKey = null;
        if (preg_match('/^\s*encryption\.key\s*=\s*(.+?)\s*$/mi', $existing, $match) && trim($match[1], " \t\n\r\0\x0B'\"") !== '') $encryptionKey = trim($match[1]);
        $baseUrl = 'https://'.$instance->slug.'.ikontrol.solutions/';
        $lines = [
            'CI_ENVIRONMENT = production', '',
            'app.baseURL = '.$this->ciEnvValue($baseUrl), '',
            'database.default.hostname = '.$this->ciEnvValue(config('ikontrol.db.host')),
            'database.default.database = '.$this->ciEnvValue($instance->db_name),
            'database.default.username = '.$this->ciEnvValue(config('ikontrol.db.username')),
            'database.default.password = '.$this->ciEnvValue(config('ikontrol.db.password')),
            'database.default.DBDriver = MySQLi',
            'database.default.DBPrefix = '.$this->ciEnvValue(config('ikontrol.db.table_prefix', 'ikontrol_')),
            'database.default.port = '.(int) config('ikontrol.db.port'),
        ];
        if ($encryptionKey !== null) $lines = array_merge($lines, ['', 'encryption.key = '.$encryptionKey]);
        File::replace($target, implode(PHP_EOL, $lines).PHP_EOL); @chmod($target, 0600);
        $this->installDatabaseCheckCommand($path);
        return ['encryption_key_present' => $encryptionKey !== null, 'document_root' => $path];
    }

    public function templateHasEncryptionKey(IkontrolInstance $instance): bool
    {
        $target = $this->safeInstancePath($instance).DIRECTORY_SEPARATOR.'.env';
        if (! File::exists($target)) return false;
        return preg_match('/^\s*encryption\.key\s*=\s*([^\s#]+)\s*$/mi', (string) File::get($target), $match) === 1 && trim($match[1], "'\"") !== '';
    }

    public function templateDocumentRoot(IkontrolInstance $instance): string
    {
        return $this->safeInstancePath($instance);
    }

    public function verifyDependencies(IkontrolInstance $instance): void
    {
        $path = $this->safeInstancePath($instance);
        if (! is_file($path.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php')) {
            throw new RuntimeException('La versión no incluye dependencias de producción. Genere el artefacto con Composer antes de registrarlo.');
        }
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
        $expectedFolder = $instance->slug.config('ikontrol.folder_suffix');
        $expected = $root === false ? '' : $this->normalizePath($root.DIRECTORY_SEPARATOR.$instance->folder_name);
        $path = $this->normalizePath($instance->absolute_path ?: $expected);
        $comparablePath = PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
        $comparableExpected = PHP_OS_FAMILY === 'Windows' ? strtolower($expected) : $expected;
        $comparableRoot = PHP_OS_FAMILY === 'Windows' ? strtolower((string) $root) : (string) $root;
        if ($root === false || $instance->folder_name !== $expectedFolder || $comparablePath !== $comparableExpected || str_contains($path, '..') || ! str_starts_with($comparablePath, $comparableRoot.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('La ruta de la instalación no es segura.');
        }
        if (is_link($path)) {
            throw new RuntimeException('La carpeta de la instalación no puede ser un symlink.');
        }
        return $path;
    }

    public function runTemplateCommand(IkontrolInstance $instance, string $command, array $arguments = []): array
    {
        return ($this->spark ?? app(AllowedSparkRunner::class))->run($this->safeInstancePath($instance), $command, $arguments);
    }

    public function installOperationalCommandsFor(IkontrolInstance $instance): void
    {
        $path=$this->safeInstancePath($instance); $this->installDatabaseCheckCommand($path); $this->installOperationalCommands($path);
    }

    public function runSensitiveTemplateCommand(IkontrolInstance $instance, string $command, array $arguments, string $input): array
    {
        $this->installOperationalCommandsFor($instance);
        return ($this->spark ?? app(AllowedSparkRunner::class))->runWithInput($this->safeInstancePath($instance),$command,$arguments,$input);
    }

    private function envValue(mixed $value): string
    {
        $value = str_replace(["\\", '"', '$', "\r", "\n"], ['\\\\', '\\"', '\\$', '', ''], (string) $value);
        return '"'.$value.'"';
    }

    private function ciEnvValue(mixed $value): string
    {
        return "'".str_replace(["\\", "'", "\r", "\n"], ['\\\\', "\\'", '', ''], (string) $value)."'";
    }

    private function installDatabaseCheckCommand(string $path): void
    {
        $target = $path.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Commands'.DIRECTORY_SEPARATOR.'IkontrolDatabaseCheck.php';
        File::ensureDirectoryExists(dirname($target), 0750);
        File::put($target, <<<'PHP'
<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class IkontrolDatabaseCheck extends BaseCommand
{
    protected $group = 'iKontrol';
    protected $name = 'ikontrol:database-check';
    protected $description = 'Verifica la conexión configurada mediante una consulta de solo lectura.';

    public function run(array $params)
    {
        try {
            $database = \Config\Database::connect();
            $database->query('SELECT 1')->getRow();
            CLI::write('CONNECTED', 'green');
        } catch (\Throwable) {
            CLI::error('ERROR');
            throw new \RuntimeException('No fue posible conectar con la base configurada.');
        }
    }
}
PHP);
        @chmod($target, 0640);
    }

    private function installOperationalCommands(string $path): void
    {
        $directory=$path.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Commands'; File::ensureDirectoryExists($directory,0750);
        File::put($directory.DIRECTORY_SEPARATOR.'IkontrolAdminProvision.php', <<<'PHP'
<?php
namespace App\Commands;
use CodeIgniter\CLI\{BaseCommand,CLI};
final class IkontrolAdminProvision extends BaseCommand {
 protected $group='iKontrol'; protected $name='ikontrol:admin-provision'; protected $usage='ikontrol:admin-provision <name> <email>';
 public function run(array $params){if(count($params)!==2||!filter_var($params[1],FILTER_VALIDATE_EMAIL))throw new \RuntimeException('Datos de administrador inválidos.');$password=rtrim((string)fgets(STDIN),"\r\n");if(strlen($password)<12)throw new \RuntimeException('La contraseña debe tener al menos 12 caracteres.');$db=\Config\Database::connect();if($db->table('users')->where('email',$params[1])->where('deleted',0)->countAllResults()>0)throw new \RuntimeException('El correo ya existe.');$parts=preg_split('/\s+/',trim($params[0]),2);$ok=$db->table('users')->insert(['first_name'=>$parts[0],'last_name'=>$parts[1]??'','user_type'=>'staff','is_admin'=>1,'role_id'=>0,'email'=>$params[1],'password'=>password_hash($password,PASSWORD_DEFAULT),'status'=>'active','client_id'=>0,'is_primary_contact'=>0,'job_title'=>'Admin','disable_login'=>0,'gender'=>'male','language'=>'','enable_web_notification'=>1,'enable_email_notification'=>1,'created_at'=>date('Y-m-d H:i:s'),'requested_account_removal'=>0,'deleted'=>0]);unset($password);if(!$ok)throw new \RuntimeException('No fue posible crear el administrador.');CLI::write('ADMIN_CREATED','green');}
}
PHP);
        File::put($directory.DIRECTORY_SEPARATOR.'IkontrolAdminPasswordSet.php', <<<'PHP'
<?php
namespace App\Commands;
use CodeIgniter\CLI\{BaseCommand,CLI};
final class IkontrolAdminPasswordSet extends BaseCommand {
 protected $group='iKontrol'; protected $name='ikontrol:admin-password-set'; protected $usage='ikontrol:admin-password-set <email>';
 public function run(array $params){if(count($params)!==1||!filter_var($params[0],FILTER_VALIDATE_EMAIL))throw new \RuntimeException('Correo inválido.');$password=rtrim((string)fgets(STDIN),"\r\n");if(strlen($password)<12)throw new \RuntimeException('La contraseña debe tener al menos 12 caracteres.');$db=\Config\Database::connect();$users=$db->table('users')->select('id')->where(['email'=>$params[0],'deleted'=>0,'user_type'=>'staff'])->get()->getResultArray();if(count($users)!==1)throw new \RuntimeException('Administrador inexistente o ambiguo.');$ok=$db->table('users')->where('id',$users[0]['id'])->update(['password'=>password_hash($password,PASSWORD_DEFAULT)]);unset($password);if(!$ok)throw new \RuntimeException('No fue posible actualizar la contraseña.');CLI::write('PASSWORD_UPDATED','green');}
}
PHP);
        @chmod($directory.DIRECTORY_SEPARATOR.'IkontrolAdminProvision.php',0640); @chmod($directory.DIRECTORY_SEPARATOR.'IkontrolAdminPasswordSet.php',0640);
    }

    private function normalizePath(string $path): string
    {
        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    }
}
