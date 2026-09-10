<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InstanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'exists:clients,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'max:40',
                'regex:/\A[a-z0-9]+(?:_[a-z0-9]+)*\z/',
                Rule::unique('ikontrol_instances'),
            ],
            'folder_name' => [
                'required',
                'string',
                'max:255',
                'not_regex:#\.\.|[\\\\/]#',
                Rule::unique('ikontrol_instances'),
            ],
            'absolute_path' => ['nullable', 'string', 'max:1000'],
            'domain' => ['nullable', 'string', 'max:255'],
            'url' => ['nullable', 'url', 'max:500'],
            'db_host' => ['nullable', 'string', 'max:255'],
            'target_version' => ['nullable', 'string', 'max:50', 'regex:/\A[0-9A-Za-z._-]+\z/'],
            'db_name' => [
                'required',
                'regex:/\A[a-zA-Z0-9_]+\z/',
                'max:64',
                Rule::unique('ikontrol_instances'),
            ],
        ];
    }
}
