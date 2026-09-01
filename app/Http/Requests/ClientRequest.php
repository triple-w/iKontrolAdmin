<?php
namespace App\Http\Requests; use Illuminate\Foundation\Http\FormRequest;
class ClientRequest extends FormRequest { public function authorize(): bool{return true;} public function rules(): array{return ['name'=>'required|string|max:255','legal_name'=>'nullable|string|max:255','rfc'=>'nullable|string|max:20','email'=>'nullable|email|max:255','phone'=>'nullable|string|max:30','notes'=>'nullable|string|max:5000','active'=>'sometimes|boolean'];} }
