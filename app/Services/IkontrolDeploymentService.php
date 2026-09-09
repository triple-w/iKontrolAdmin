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

    public function installOperationalCommandsFor(IkontrolInstance $instance): array
    {
        $path=$this->safeInstancePath($instance);
        $this->guardManagedCommandTargets($path);
        $this->installDatabaseCheckCommand($path);
        $this->installOperationalCommands($path);
        $this->writeDiagnosticToolsManifest($path);
        $verification = ($this->spark ?? app(AllowedSparkRunner::class))->run($path, 'list');
        $required = ['ikontrol:database-check', 'ikontrol:log-check', 'ikontrol:admin-diagnose', 'ikontrol:dashboard-check'];
        $stdout=(string)($verification['stdout']??$verification['output']??'');
        $missing = array_values(array_filter($required, fn ($command) => ! str_contains($stdout, $command)));
        $manifest=json_decode((string)File::get($path.DIRECTORY_SEPARATOR.'.ikontroladmin-diagnostic-tools.json'),true);$details=[];
        $files=['ikontrol:database-check'=>'IkontrolDatabaseCheck.php','ikontrol:log-check'=>'IkontrolLogCheck.php','ikontrol:admin-diagnose'=>'IkontrolAdminDiagnose.php','ikontrol:dashboard-check'=>'IkontrolDashboardCheck.php'];
        foreach($files as$command=>$file){$target=$path.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Commands'.DIRECTORY_SEPARATOR.$file;$actual=is_file($target)?hash_file('sha256',$target):null;$expected=$manifest['files'][$file]??null;$details[$command]=['file_exists'=>is_file($target),'hash_ok'=>$actual!==null&&$expected!==null&&hash_equals($expected,$actual),'spark_discovered'=>str_contains($stdout,$command),'permissions'=>is_file($target)?substr(sprintf('%o',fileperms($target)),-4):null,'owner'=>is_file($target)?fileowner($target):null,'group'=>is_file($target)?filegroup($target):null,'reason'=>str_contains($stdout,$command)?null:'COMMAND_NOT_FOUND'];}
        if (($verification['exit_code'] ?? 1) !== 0 || $missing) {
            $runId = strtoupper(bin2hex(random_bytes(4)));
            $failed = array_map(fn ($command) => $command.' [file='.(($details[$command]['file_exists'] ?? false) ? 'YES' : 'NO').', hash='.(($details[$command]['hash_ok'] ?? false) ? 'OK' : 'FAILED').', discovery=NO]', $missing ?: $required);
            $stderr = mb_substr((string) ($verification['stderr_tail'] ?? ''), 0, 300);
            throw new RuntimeException('Diagnóstico falló en MANAGED_COMMAND_DISCOVERY ['.$runId.']: '.implode('; ', $failed).($stderr !== '' ? '. stderr: '.$stderr : '.'));
        }
        $protocol = app(ManagedCommandJsonProtocol::class);
        $databaseCheck = ($this->spark ?? app(AllowedSparkRunner::class))->run($path, 'ikontrol:database-check');
        $databaseResult = $protocol->decodeProcess($databaseCheck);
        if (! in_array($databaseResult['status'] ?? null, ['READY', 'SUCCESS', 'OK'], true)) throw new RuntimeException('Diagnóstico falló en JSON_PROTOCOL_DATABASE_CHECK.');
        $logCheck = ($this->spark ?? app(AllowedSparkRunner::class))->run($path, 'ikontrol:log-check');
        $logResult = $protocol->decodeProcess($logCheck);
        return ['action'=>'INSTALL_DIAGNOSTIC_TOOLS','before_status'=>'UNKNOWN','action_result'=>'FILES_WRITTEN','verification_result'=>'SPARK_DISCOVERY_AND_JSON_PROTOCOL_OK','after_status'=>'READY','commands'=>$details,'protocol'=>['database_check'=>$databaseResult['_output_protocol'],'log_check'=>$logResult['_output_protocol']],'runner'=>['cwd'=>$verification['cwd']??$path,'php_binary'=>$verification['php_binary']??null,'exit_code'=>$verification['exit_code']??null,'stdout_tail'=>$verification['stdout_tail']??null,'stderr_tail'=>$verification['stderr_tail']??null]];
    }

    public function runSensitiveTemplateCommand(IkontrolInstance $instance, string $command, array $arguments, string $input): array
    {
        $this->installOperationalCommandsFor($instance);
        return ($this->spark ?? app(AllowedSparkRunner::class))->runWithInput($this->safeInstancePath($instance),$command,$arguments,$input);
    }

    public function runStampMovement(IkontrolInstance $instance,string $action,int $quantity,string $reason,string $requestId):array
    {
        $this->installOperationalCommandsFor($instance);
        return ($this->spark ?? app(AllowedSparkRunner::class))->runStampMovement($this->safeInstancePath($instance),$action,$quantity,$reason,$requestId);
    }

    public function runDiagnosticCommand(IkontrolInstance $instance, string $command, string $email): array
    {
        $this->installOperationalCommandsFor($instance);
        return ($this->spark ?? app(AllowedSparkRunner::class))->runDiagnostic($this->safeInstancePath($instance), $command, $email);
    }

    public function runInstalledDiagnosticCommand(IkontrolInstance $instance, string $command, string $email): array
    {
        return ($this->spark ?? app(AllowedSparkRunner::class))->runDiagnostic($this->safeInstancePath($instance), $command, $email);
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
            CLI::write("IKONTROL_JSON_BEGIN\n".json_encode(['status'=>'READY'])."\nIKONTROL_JSON_END", 'green');
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
 public function run(array $params){if(count($params)!==2||!filter_var($params[1],FILTER_VALIDATE_EMAIL))throw new \RuntimeException('Datos de administrador inválidos.');$password=rtrim((string)fgets(STDIN),"\r\n");if(strlen($password)<12)throw new \RuntimeException('La contraseña debe tener al menos 12 caracteres.');$db=\Config\Database::connect();if($db->table('users')->where('email',$params[1])->where('deleted',0)->countAllResults()>0)throw new \RuntimeException('El correo ya existe.');$parts=preg_split('/\s+/',trim($params[0]),2);$db->transStart();$ok=$db->table('users')->insert(['first_name'=>$parts[0],'last_name'=>$parts[1]??'','user_type'=>'staff','is_admin'=>1,'role_id'=>0,'email'=>strtolower($params[1]),'password'=>password_hash($password,PASSWORD_DEFAULT),'status'=>'active','client_id'=>0,'is_primary_contact'=>0,'job_title'=>'Admin','disable_login'=>0,'gender'=>'male','language'=>'','enable_web_notification'=>1,'enable_email_notification'=>1,'created_at'=>date('Y-m-d H:i:s'),'requested_account_removal'=>0,'deleted'=>0]);$userId=$db->insertID();if($ok&&$db->tableExists('team_member_job_info'))$ok=$db->table('team_member_job_info')->insert(['user_id'=>$userId,'salary'=>0,'salary_term'=>'','date_of_hire'=>date('Y-m-d')]);unset($password);$db->transComplete();if(!$ok||!$db->transStatus())throw new \RuntimeException('No fue posible crear el administrador completo.');CLI::write('ADMIN_CREATED','green');}
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
        File::put($directory.DIRECTORY_SEPARATOR.'IkontrolStampsStatus.php', <<<'PHP'
<?php
namespace App\Commands;
use App\Services\Fiscal\FiscalStampAdminService;use CodeIgniter\CLI\{BaseCommand,CLI};
final class IkontrolStampsStatus extends BaseCommand{protected $group='iKontrol';protected $name='ikontrol:stamps-status';public function run(array$params){$service=new FiscalStampAdminService();$accounts=$service->getAccounts();$credited=0;$consumed=0;$history=[];foreach($accounts as$a){foreach($service->getHistory((int)$a['issuer_profile_id'])as$m){$q=(int)$m['quantity'];if($q>0)$credited+=$q;elseif(in_array($m['movement_type'],['document_consumption','reconciliation_consumption','cancellation_request','cancellation_status_query','adjustment_debit'],true))$consumed+=abs($q);$history[]=$m;}}usort($history,fn($a,$b)=>(int)$b['id']<=>(int)$a['id']);CLI::write("IKONTROL_JSON_BEGIN\n".json_encode(['status'=>'SUCCESS','balance'=>array_sum(array_map(fn($a)=>(int)$a['available_balance'],$accounts)),'credited'=>$credited,'consumed'=>$consumed,'accounts'=>$accounts,'movements'=>array_slice($history,0,20)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\nIKONTROL_JSON_END");}}
PHP);
        File::put($directory.DIRECTORY_SEPARATOR.'IkontrolStampsAdjust.php', <<<'PHP'
<?php
namespace App\Commands;
use App\Services\Fiscal\FiscalStampAdminService;use CodeIgniter\CLI\{BaseCommand,CLI};
final class IkontrolStampsAdjust extends BaseCommand{protected $group='iKontrol';protected $name='ikontrol:stamps-adjust';public function run(array$p){if(count($p)!==4||!in_array($p[0],['credit','debit'],true)||!ctype_digit($p[1])||(int)$p[1]<1||!preg_match('/^[a-f0-9]{32}$/',$p[2])||trim($p[3])==='')throw new \RuntimeException('Movimiento inválido.');$service=new FiscalStampAdminService();$accounts=$service->getAccounts();if(count($accounts)!==1)throw new \RuntimeException('La instancia debe tener exactamente un emisor fiscal.');$a=$accounts[0];$env=in_array($a['profile_environment']??null,['development','production'],true)?$a['profile_environment']:'development';$m=$p[0]==='credit'?$service->credit((int)$a['issuer_profile_id'],$env,(int)$p[1],$p[3],null,$p[2]):$service->debit((int)$a['issuer_profile_id'],$env,(int)$p[1],$p[3],null,$p[2]);CLI::write("IKONTROL_JSON_BEGIN\n".json_encode(['status'=>'SUCCESS','available_after'=>(int)$m->available_after,'movement_id'=>(int)$m->id])."\nIKONTROL_JSON_END");}}
PHP);
        File::put($directory.DIRECTORY_SEPARATOR.'IkontrolLoggingStatus.php', <<<'PHP'
<?php
namespace App\Commands;
use CodeIgniter\CLI\{BaseCommand,CLI};
final class IkontrolLoggingStatus extends BaseCommand{protected $group='iKontrol';protected $name='ikontrol:logging-status';public function run(array$p){if($p)throw new \RuntimeException('Este comando no acepta argumentos.');$dir=WRITEPATH.'logs';$logger=config('Logger');$handlers=[];foreach(array_keys((array)$logger->handlers)as$class)$handlers[]=basename(str_replace('\\','/',$class));$files=is_dir($dir)?glob($dir.DIRECTORY_SEPARATOR.'*.log')?:[]:[];CLI::write("IKONTROL_JSON_BEGIN\n".json_encode(['status'=>'SUCCESS','directory_exists'=>file_exists($dir),'is_directory'=>is_dir($dir),'writable'=>is_dir($dir)&&is_writable($dir),'file_count'=>count($files),'environment'=>ENVIRONMENT,'threshold'=>$logger->threshold,'handlers'=>$handlers],JSON_UNESCAPED_SLASHES)."\nIKONTROL_JSON_END");}}
PHP);
        File::put($directory.DIRECTORY_SEPARATOR.'IkontrolLogCheck.php', <<<'PHP'
<?php
namespace App\Commands;
use CodeIgniter\CLI\{BaseCommand,CLI};
final class IkontrolLogCheck extends BaseCommand{protected $group='iKontrol';protected $name='ikontrol:log-check';public function run(array$p){if($p)throw new \RuntimeException('Este comando no acepta argumentos.');$dir=WRITEPATH.'logs';$logger=config('Logger');$before=is_dir($dir)?glob($dir.DIRECTORY_SEPARATOR.'*.log')?:[]:[];$beforeState=[];foreach($before as$f)$beforeState[basename($f)]=[filesize($f),filemtime($f)];log_message('error','IKONTROL_ADMIN_LOG_CHECK');clearstatcache();$after=is_dir($dir)?glob($dir.DIRECTORY_SEPARATOR.'*.log')?:[]:[];$written=false;$testFile=null;foreach($after as$f){$state=[filesize($f),filemtime($f)];if(!isset($beforeState[basename($f)])||$beforeState[basename($f)]!==$state){$written=true;$testFile=basename($f);break;}}$handlers=array_map(fn($c)=>basename(str_replace('\\','/',$c)),array_keys((array)$logger->handlers));$reason=$written?null:($logger->threshold===0?'LOGGER_DISABLED_BY_THRESHOLD':(!is_dir($dir)?'LOG_DIRECTORY_MISSING':(!is_writable($dir)?'LOG_DIRECTORY_NOT_WRITABLE':'HANDLER_DID_NOT_WRITE')));CLI::write("IKONTROL_JSON_BEGIN\n".json_encode(['logs_dir_exists'=>is_dir($dir),'logs_dir_writable'=>is_dir($dir)&&is_writable($dir),'threshold'=>$logger->threshold,'logger_threshold'=>$logger->threshold,'handlers'=>$handlers,'test_write_attempted'=>true,'test_file_created'=>$written,'test_file'=>$testFile,'reason'=>$reason,'status'=>$written?'READY':'FAILED'],JSON_UNESCAPED_SLASHES)."\nIKONTROL_JSON_END");}}
PHP);
        File::put($directory.DIRECTORY_SEPARATOR.'IkontrolAdminDiagnose.php', <<<'PHP'
<?php
namespace App\Commands;
use CodeIgniter\CLI\{BaseCommand,CLI};
final class IkontrolAdminDiagnose extends BaseCommand{protected $group='iKontrol';protected $name='ikontrol:admin-diagnose';public function run(array$p){if(count($p)!==1||!filter_var($p[0],FILTER_VALIDATE_EMAIL))throw new \RuntimeException('Correo inválido.');$emit=fn($data)=>CLI::write("IKONTROL_JSON_BEGIN\n".json_encode($data)."\nIKONTROL_JSON_END");$db=\Config\Database::connect();$tables=array_flip($db->listTables());$name=fn($n)=>$db->prefixTable($n);$result=['user_exists'=>false,'active'=>false,'staff'=>false,'role_ok'=>false,'permissions_ok'=>false,'profile_ok'=>false,'dashboard_prerequisites'=>[],'status'=>'FAILED','reason'=>null];if(!isset($tables[$name('users')])){$result['dashboard_prerequisites']['users_table']=false;$result['reason']='TABLE_MISSING';$emit($result);return;}$user=$db->table('users')->select('id,user_type,is_admin,role_id,status,disable_login,deleted')->where('email',strtolower($p[0]))->get()->getRowArray();if(!$user){$result['reason']='USER_NOT_FOUND';$emit($result);return;}$result['user_exists']=true;$result['active']=$user['status']==='active'&&!(int)$user['disable_login']&&!(int)$user['deleted'];$result['staff']=$user['user_type']==='staff';$result['role_ok']=(int)$user['is_admin']===1&&((int)$user['role_id']===0);$result['permissions_ok']=$result['role_ok'];$jobTable=isset($tables[$name('team_member_job_info')]);$result['profile_ok']=$jobTable&&$db->table('team_member_job_info')->where('user_id',$user['id'])->countAllResults()===1;foreach(['settings','dashboards','team']as$t)$result['dashboard_prerequisites'][$t.'_table']=isset($tables[$name($t)]);$critical=$result['active']&&$result['staff']&&$result['role_ok']&&$result['permissions_ok']&&!in_array(false,$result['dashboard_prerequisites'],true);$result['status']=$critical?($result['profile_ok']?'READY':'WARNING'):'FAILED';$result['reason']=!$result['active']?'INACTIVE':(!$result['role_ok']?'ROLE_MISSING':(!$result['permissions_ok']?'PERMISSIONS_MISSING':(!$result['profile_ok']?'TEAM_MEMBER_INFO_MISSING':(in_array(false,$result['dashboard_prerequisites'],true)?'TABLE_MISSING':null))));$emit($result);}}
PHP);
        File::put($directory.DIRECTORY_SEPARATOR.'IkontrolDashboardCheck.php', <<<'PHP'
<?php
namespace App\Commands;
use CodeIgniter\CLI\{BaseCommand,CLI};
final class IkontrolDashboardCheck extends BaseCommand{protected $group='iKontrol';protected $name='ikontrol:dashboard-check';public function run(array$p){if(count($p)!==1||!filter_var($p[0],FILTER_VALIDATE_EMAIL))throw new \RuntimeException('Correo inválido.');$db=\Config\Database::connect();$tables=array_flip($db->listTables());$name=fn($n)=>$db->prefixTable($n);$checks=[];foreach(['users','roles','team','settings','dashboards','custom_widgets']as$t)$checks[$t.'_table']=isset($tables[$name($t)]);$user=null;if($checks['users_table'])$user=$db->table('users')->select('id,user_type,is_admin,role_id,status,disable_login,deleted')->where('email',strtolower($p[0]))->get()->getRowArray();$checks['user_access_record']=$user&&$user['status']==='active'&&!(int)$user['deleted']&&!(int)$user['disable_login'];$checks['staff_dashboard']=$user&&$user['user_type']==='staff';$checks['admin_access']=$user&&(int)$user['is_admin']===1&&(int)$user['role_id']===0;$status=in_array(false,$checks,true)?'FAILED':'READY';$reason=null;if(!$checks['users_table']||!$checks['roles_table']||!$checks['team_table'])$reason='TABLE_MISSING';elseif(!$checks['settings_table'])$reason='SETTING_MISSING';elseif(!$checks['dashboards_table'])$reason='DASHBOARD_MISSING';elseif(!$checks['custom_widgets_table'])$reason='WIDGET_CONFIG_MISSING';elseif(!$checks['user_access_record']||!$checks['admin_access'])$reason='ADMIN_INVALID';CLI::write("IKONTROL_JSON_BEGIN\n".json_encode(['status'=>$status,'reason'=>$reason,'checks'=>$checks])."\nIKONTROL_JSON_END");}}
PHP);
        foreach(['IkontrolAdminProvision.php','IkontrolAdminPasswordSet.php','IkontrolStampsStatus.php','IkontrolStampsAdjust.php','IkontrolLoggingStatus.php','IkontrolLogCheck.php','IkontrolAdminDiagnose.php','IkontrolDashboardCheck.php']as$file)@chmod($directory.DIRECTORY_SEPARATOR.$file,0640);
    }

    private function normalizePath(string $path): string
    {
        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    }

    private function guardManagedCommandTargets(string $path): void
    {
        $directory = $path.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Commands';
        $commands = [
            'IkontrolDatabaseCheck.php' => 'ikontrol:database-check',
            'IkontrolLogCheck.php' => 'ikontrol:log-check',
            'IkontrolAdminDiagnose.php' => 'ikontrol:admin-diagnose',
            'IkontrolDashboardCheck.php' => 'ikontrol:dashboard-check',
        ];
        $manifest = $path.DIRECTORY_SEPARATOR.'.ikontroladmin-diagnostic-tools.json';
        $owned = is_file($manifest) ? json_decode((string) File::get($manifest), true) : [];
        foreach ($commands as $file => $command) {
            $target = $directory.DIRECTORY_SEPARATOR.$file;
            if (! is_file($target)) continue;
            $hash = hash_file('sha256', $target);
            $knownHash = $owned['files'][$file] ?? null;
            $legacySignature = str_contains((string) File::get($target), $command) && str_contains((string) File::get($target), 'namespace App\\Commands');
            if (($knownHash && ! hash_equals($knownHash, $hash)) || (! $knownHash && ! $legacySignature)) {
                throw new RuntimeException('Existe un comando no administrado que no puede sobrescribirse: '.$file);
            }
        }
    }

    private function writeDiagnosticToolsManifest(string $path): void
    {
        $directory = $path.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Commands';
        $files = [];
        foreach (['IkontrolDatabaseCheck.php', 'IkontrolLogCheck.php', 'IkontrolAdminDiagnose.php', 'IkontrolDashboardCheck.php'] as $file) {
            if (is_file($directory.DIRECTORY_SEPARATOR.$file)) $files[$file] = hash_file('sha256', $directory.DIRECTORY_SEPARATOR.$file);
        }
        File::put($path.DIRECTORY_SEPARATOR.'.ikontroladmin-diagnostic-tools.json', json_encode(['managed_by' => 'iKontrolAdmin', 'version' => config('ikontrol.deployment.diagnostic_tools_version'), 'files' => $files], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($path.DIRECTORY_SEPARATOR.'.ikontroladmin-diagnostic-tools.json', 0640);
    }
}
