<?php
namespace App\Services;
use App\Models\IkontrolInstance;use Illuminate\Support\Str;use RuntimeException;
class IkontrolStampService{
 public function __construct(private IkontrolDeploymentService$d,private AuditService$a){}
 public function status(IkontrolInstance$i):array{$this->d->installOperationalCommandsFor($i);return$this->json($this->d->runTemplateCommand($i,'ikontrol:stamps-status',[]));}
 public function move(IkontrolInstance$i,string$action,int$quantity,string$reason):array{if(!in_array($action,['credit','debit'],true)||$quantity<1||$quantity>1000000)throw new RuntimeException('Cantidad o tipo de movimiento inválido.');$reason=trim(strip_tags($reason));if($reason===''||mb_strlen($reason)>500)throw new RuntimeException('El motivo es obligatorio y admite hasta 500 caracteres.');$r=$this->d->runStampMovement($i,$action,$quantity,$reason,strtolower(Str::random(32)));if(($r['exit_code']??1)!==0)throw new RuntimeException(str_contains(strtolower($r['output']??''),'insufficient')?'Saldo de timbres insuficiente.':'No fue posible aplicar el movimiento de timbres.');$result=$this->json($r);$this->a->record($action==='credit'?'instance_stamps_credit':'instance_stamps_debit','Movimiento administrativo de timbres ejecutado.',$i,['quantity'=>$quantity,'reason'=>$reason,'success'=>true]);return$result;}
 private function json(array$process):array{$data=app(ManagedCommandJsonProtocol::class)->decodeProcess($process);if(($data['status']??null)==='SUCCESS')return$data;throw new RuntimeException('La instancia devolvió una respuesta de timbres inválida.');}
}
