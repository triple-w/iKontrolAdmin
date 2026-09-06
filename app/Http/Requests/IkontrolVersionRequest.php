<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IkontrolVersionRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $id = $this->route('version')?->id;
        return [
            'version' => ['required', 'string', 'max:50', 'regex:/\A[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.-]+)?\z/', Rule::unique('ikontrol_versions')->ignore($id)],
            'name' => ['required', 'string', 'max:255'],
            'source_type' => ['required', Rule::in(['archive', 'git'])],
            'source_reference' => ['required', 'string', 'max:255', function ($attribute, $value, $fail) {
                if ($this->input('source_type') === 'archive' && (! preg_match('/\A[A-Za-z0-9._-]+\.zip\z/i', $value) || str_contains($value, '..'))) $fail('La referencia archive debe ser un nombre ZIP seguro.');
                if ($this->input('source_type') === 'git' && preg_match('#[:@\\\\/]#', $value)) $fail('La referencia git debe ser solamente un tag o referencia, sin credenciales ni URL.');
            }],
            'checksum' => ['nullable', 'regex:/\A[a-fA-F0-9]{64}\z/'],
            'active' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
