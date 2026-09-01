<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest; use Illuminate\Validation\Rule;
class ProvisionInstanceRequest extends FormRequest {
 public function authorize():bool{return true;}
 public function rules():array{return ['client_mode'=>['required',Rule::in(['existing','new'])],'client_id'=>['nullable','required_if:client_mode,existing','prohibited_if:client_mode,new','exists:clients,id'],'new_client_name'=>['nullable','required_if:client_mode,new','prohibited_if:client_mode,existing','string','max:255'],'name'=>['required','string','max:255'],'slug'=>['required','regex:/\A[a-z0-9]+(?:_[a-z0-9]+)*\z/','max:40','unique:ikontrol_instances,slug']];}
 public function messages():array{return ['client_id.required_if'=>'Seleccione un cliente existente.','client_id.prohibited_if'=>'No envíe un cliente existente al crear uno nuevo.','new_client_name.required_if'=>'Escriba el nombre del cliente nuevo.','new_client_name.prohibited_if'=>'No envíe un cliente nuevo al usar uno existente.'];}
}
