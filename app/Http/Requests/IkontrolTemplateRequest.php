<?php

namespace App\Http\Requests;

use App\Rules\SafeTemplateRelativePath;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IkontrolTemplateRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $id = $this->route('template')?->id;
        return [
            'version' => ['required', 'max:50', 'regex:/\A\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?\z/', Rule::unique('ikontrol_templates')->ignore($id)],
            'name' => ['required', 'string', 'max:255'],
            'app_version' => ['required', 'string', 'max:100'],
            'schema_version' => ['required', 'string', 'max:100'],
            'archive_path' => ['required', 'string', 'max:500', new SafeTemplateRelativePath('zip')],
            'database_dump_path' => ['required', 'string', 'max:500', new SafeTemplateRelativePath('sql')],
            'archive_sha256' => ['required', 'regex:/\A[a-f0-9]{64}\z/i'],
            'database_sha256' => ['required', 'regex:/\A[a-f0-9]{64}\z/i'],
            'active' => ['nullable', 'boolean'], 'is_default' => ['nullable', 'boolean'], 'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
