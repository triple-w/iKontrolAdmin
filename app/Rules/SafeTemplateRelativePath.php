<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SafeTemplateRelativePath implements ValidationRule
{
    public function __construct(private string $extension) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $path = (string) $value;
        if ($path === '' || str_contains($path, '..') || str_contains($path, '\\') || str_starts_with($path, '/')
            || preg_match('#^(?:[A-Za-z]:|https?://)#i', $path)
            || ! preg_match('#\A[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*\.'.preg_quote($this->extension, '#').'\z#i', $path)) {
            $fail('La :attribute debe ser una ruta relativa segura dentro de IKONTROL_TEMPLATE_ROOT.');
        }
    }
}
