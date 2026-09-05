<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FactucareSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['rfc' => mb_strtoupper(trim((string) $this->input('rfc')))]);
    }

    public function rules(): array
    {
        return ['rfc' => ['required', 'string', 'min:10', 'max:20', 'regex:/\A[A-Z0-9&Ñ]+\z/u']];
    }
}
