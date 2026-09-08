<?php
namespace App\Http\Controllers\Admin;
use App\Models\IkontrolInstance;use App\Services\{IkontrolStampService,InstanceLogService};use Illuminate\Http\Request;use Illuminate\Validation\Rule;use Throwable;
class InstanceDiagnosticsController{
 public function stamps(Request$r,IkontrolInstance$instance,IkontrolStampService$s){$data=$r->validate(['action'=>['required',Rule::in(['credit','debit'])],'quantity'=>['required','integer','min:1','max:1000000'],'reason'=>['required','string','max:500']]);try{$s->move($instance,$data['action'],(int)$data['quantity'],$data['reason']);return redirect()->route('instances.show',[$instance,'tab'=>'stamps'])->with('success','Movimiento de timbres aplicado.');}catch(Throwable$e){return redirect()->route('instances.show',[$instance,'tab'=>'stamps'])->with('error',$e->getMessage());}}
 public function log(Request$r,IkontrolInstance$instance,string$file,InstanceLogService$s){try{return response()->json($s->read($instance,$file,(int)$r->integer('lines',100),$r->string('search')->toString()));}catch(Throwable$e){abort(404,$e->getMessage());}}
 public function clearLogs(IkontrolInstance$instance,InstanceLogService$s){try{$s->clear($instance);return redirect()->route('instances.show',[$instance,'tab'=>'logs'])->with('success','Logs limpiados.');}catch(Throwable$e){return redirect()->route('instances.show',[$instance,'tab'=>'logs'])->with('error',$e->getMessage());}}
}
