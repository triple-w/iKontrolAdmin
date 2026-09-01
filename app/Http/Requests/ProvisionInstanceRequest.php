<?php
namespace App\Http\Requests; use Illuminate\Foundation\Http\FormRequest;
class ProvisionInstanceRequest extends FormRequest { public function authorize(): bool{return true;} public function rules(): array{return ['client_id'=>'nullable|required_without:new_client_name|exists:clients,id','new_client_name'=>'nullable|required_without:client_id|string|max:255','name'=>'required|string|max:255','slug'=>'required|regex:/\A[a-z0-9]+(?:_[a-z0-9]+)*\z/|max:40|unique:ikontrol_instances,slug'];} }
