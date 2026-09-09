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
final class IkontrolStampsStatus extends BaseCommand{protected $group='iKontrol';protected $name='ikontrol:stamps-status';public function run(array$params){$service=new FiscalStampAdminService();$accounts=$service->getAccounts();$credited=0;$consumed=0;$history=[];foreach($accounts as$a){foreach($service->getHistory((int)$a['issuer_profile_id'])as$m){$q=(int)$m['quantity'];if($q>0)$credited+=$q;elseif(in_array($m['movement_type'],['document_consumption','reconciliation_consumption','cancellation_request','cancellation_status_query','adjustment_debit'],true))$consumed+=abs($q);$history[]=$m;}}usort($history,fn($a,$b)=>(int)$b['id']<=>(int)$a['id']);CLI::write(json_encode(['status'=>'SUCCESS','balance'=>array_sum(array_map(fn($a)=>(int)$a['available_balance'],$accounts)),'credited'=>$credited,'consumed'=>$consumed,'accounts'=>$accounts,'movements'=>array_slice($history,0,20)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}}
PHP);
        File::put($directory.DIRECTORY_SEPARATOR.'IkontrolStampsAdjust.php', <<<'PHP'
<?php
namespace App\Commands;
use App\Services\Fiscal\FiscalStampAdminService;use CodeIgniter\CLI\{BaseCommand,CLI};
final class IkontrolStampsAdjust extends BaseCommand{protected $group='iKontrol';protected $name='ikontrol:stamps-adjust';public function run(array$p){if(count($p)!==4||!in_array($p[0],['credit','debit'],true)||!ctype_digit($p[1])||(int)$p[1]<1||!preg_match('/^[a-f0-9]{32}$/',$p[2])||trim($p[3])==='')throw new \RuntimeException('Movimiento inválido.');$service=new FiscalStampAdminService();$accounts=$service->getAccounts();if(count($accounts)!==1)throw new \RuntimeException('La instancia debe tener exactamente un emisor fiscal.');$a=$accounts[0];$env=in_array($a['profile_environment']??null,['development','production'],true)?$a['profile_environment']:'development';$m=$p[0]==='credit'?$service->credit((int)$a['issuer_profile_id'],$env,(int)$p[1],$p[3],null,$p[2]):$service->debit((int)$a['issuer_profile_id'],$env,(int)$p[1],$p[3],null,$p[2]);CLI::write(json_encode(['status'=>'SUCCESS','available_after'=>(int)$m->available_after,'movement_id'=>(int)$m->id]));}}
PHP);
        File::put($directory.DIRECTORY_SEPARATOR.'IkontrolLoggingStatus.php', <<<'PHP'
<?php
namespace App\Commands;
use CodeIgniter\CLI\{BaseCommand,CLI};
final class IkontrolLoggingStatus extends BaseCommand{protected $group='iKontrol';protected $name='ikontrol:logging-status';public function run(array$p){if($p)throw new \RuntimeException('Este comando no acepta argumentos.');$dir=WRITEPATH.'logs';$logger=config('Logger');$handlers=[];foreach(array_keys((array)$logger->handlers)as$class)$handlers[]=basename(str_replace('\\','/',$class));$files=is_dir($dir)?glob($dir.DIRECTORY_SEPARATOR.'*.log')?:[]:[];CLI::write(json_encode(['status'=>'SUCCESS','directory_exists'=>file_exists($dir),'is_directory'=>is_dir($dir),'writable'=>is_dir($dir)&&is_writable($dir),'file_count'=>count($files),'environment'=>ENVIRONMENT,'threshold'=>$logger->threshold,'handlers'=>$handlers],JSON_UNESCAPED_SLASHES));}}
PHP);
        File::put($directory.DIRECTORY_SEPARATOR.'IkontrolLogCheck.php', <<<'PHP'
<?php
namespace App\Commands;
use CodeIgniter\CLI\{BaseCommand,CLI};
final class IkontrolLogCheck extends BaseCommand{protected $group='iKontrol';protected $name='ikontrol:log-check';public function run(array$p){if($p)throw new \RuntimeException('Este comando no acepta argumentos.');$dir=WRITEPATH.'logs';$before=is_dir($dir)?glob($dir.DIRECTORY_SEPARATOR.'*.log')?:[]:[];log_message('error','IKONTROL_ADMIN_LOG_CHECK');clearstatcache();$after=is_dir($dir)?glob($dir.DIRECTORY_SEPARATOR.'*.log')?:[]:[];$written=count($after)>count($before);if(!$written)foreach($after as$file)if(filemtime($file)>=time()-5){$written=true;break;}CLI::write(json_encode(['status'=>$written?'SUCCESS':'FAILED','written'=>$written,'file_count'=>count($after)]));if(!$written)throw new \RuntimeException('El logger no escribió el evento de prueba.');}}
PHP);
        File::put($directory.DIRECTORY_SEPARATOR.'IkontrolAdminDiagnose.php', <<<'PHP'
<?php
namespace App\Commands;
use CodeIgniter\CLI\{BaseCommand,CLI};
final class IkontrolAdminDiagnose extends BaseCommand{protected $group='iKontrol';protected $name='ikontrol:admin-diagnose';public function run(array$p){if(count($p)!==1||!filter_var($p[0],FILTER_VALIDATE_EMAIL))throw new \RuntimeException('Correo inválido.');$db=\Config\Database::connect();$tables=array_flip($db->listTables());$name=fn($n)=>$db->prefixTable($n);$result=['user_exists'=>false,'active'=>false,'staff'=>false,'role_ok'=>false,'permissions_ok'=>false,'profile_ok'=>false,'dashboard_prerequisites'=>[],'status'=>'FAILED'];if(!isset($tables[$name('users')])){$result['dashboard_prerequisites']['users_table']=false;CLI::write(json_encode($result));return;}$user=$db->table('users')->select('id,user_type,is_admin,role_id,status,disable_login,deleted')->where('email',strtolower($p[0]))->get()->getRowArray();if(!$user){CLI::write(json_encode($result));return;}$result['user_exists']=true;$result['active']=$user['status']==='active'&&!(int)$user['disable_login']&&!(int)$user['deleted'];$result['staff']=$user['user_type']==='staff';$result['role_ok']=(int)$user['is_admin']===1&&((int)$user['role_id']===0);$result['permissions_ok']=$result['role_ok'];$jobTable=isset($tables[$name('team_member_job_info')]);$result['profile_ok']=$jobTable&&$db->table('team_member_job_info')->where('user_id',$user['id'])->countAllResults()===1;foreach(['settings','dashboards','team']as$t)$result['dashboard_prerequisites'][$t.'_table']=isset($tables[$name($t)]);$critical=$result['active']&&$result['staff']&&$result['role_ok']&&$result['permissions_ok']&&!in_array(false,$result['dashboard_prerequisites'],true);$result['status']=$critical?($result['profile_ok']?'READY':'WARNING'):'FAILED';CLI::write(json_encode($result));}}
PHP);
        File::put($directory.DIRECTORY_SEPARATOR.'IkontrolDashboardCheck.php', <<<'PHP'
<?php
namespace App\Commands;
use CodeIgniter\CLI\{BaseCommand,CLI};
final class IkontrolDashboardCheck extends BaseCommand{protected $group='iKontrol';protected $name='ikontrol:dashboard-check';public function run(array$p){if(count($p)!==1||!filter_var($p[0],FILTER_VALIDATE_EMAIL))throw new \RuntimeException('Correo inválido.');$db=\Config\Database::connect();$tables=array_flip($db->listTables());$name=fn($n)=>$db->prefixTable($n);$checks=[];foreach(['users','roles','team','settings','dashboards','custom_widgets']as$t)$checks[$t.'_table']=isset($tables[$name($t)]);$user=null;if($checks['users_table'])$user=$db->table('users')->select('id,user_type,is_admin,role_id,status,disable_login,deleted')->where('email',strtolower($p[0]))->get()->getRowArray();$checks['user_access_record']=$user&&$user['status']==='active'&&!(int)$user['deleted']&&!(int)$user['disable_login'];$checks['staff_dashboard']=$user&&$user['user_type']==='staff';$checks['admin_access']=$user&&(int)$user['is_admin']===1&&(int)$user['role_id']===0;$status=in_array(false,$checks,true)?'FAILED':'READY';CLI::write(json_encode(['status'=>$status,'checks'=>$checks]));}}
PHP);
        foreach(['IkontrolAdminProvision.php','IkontrolAdminPasswordSet.php','IkontrolStampsStatus.php','IkontrolStampsAdjust.php','IkontrolLoggingStatus.php','IkontrolLogCheck.php','IkontrolAdminDiagnose.php','IkontrolDashboardCheck.php']as$file)@chmod($directory.DIRECTORY_SEPARATOR.$file,0640);
    }

    private function normalizePath(string $path): string
    {
        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    }
}
