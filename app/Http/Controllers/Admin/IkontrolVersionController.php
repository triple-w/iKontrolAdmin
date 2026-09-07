<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\IkontrolTemplateRequest;
use App\Models\{IkontrolTemplate, IkontrolVersion};
use App\Services\{AuditService, IkontrolTemplateValidationService};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IkontrolVersionController extends Controller
{
    public function index(IkontrolTemplateValidationService $validator)
    {
        $templates = IkontrolTemplate::latest()->get();
        $validity = $templates->mapWithKeys(function (IkontrolTemplate $template) use ($validator) {
            try { $validator->validate($template); return [$template->id => true]; } catch (\Throwable) { return [$template->id => false]; }
        });
        $legacyVersions = IkontrolVersion::whereNotIn('version', $templates->pluck('version'))->latest()->get();
        return view('admin.versions.index', compact('templates', 'legacyVersions', 'validity'));
    }

    public function create() { return view('admin.versions.form', ['template' => new IkontrolTemplate(), 'legacyVersion' => null]); }
    public function editTemplate(IkontrolTemplate $template) { return view('admin.versions.form', compact('template') + ['legacyVersion' => null]); }

    public function editLegacy(IkontrolVersion $version)
    {
        if ($existing = IkontrolTemplate::where('version', $version->version)->first()) return redirect()->route('versions.templates.edit', $existing);
        $template = new IkontrolTemplate(['version' => $version->version, 'name' => $version->name, 'app_version' => $version->version, 'archive_path' => $version->version.'/'.basename($version->source_reference), 'database_dump_path' => $version->version.'/ikontrol-'.$version->version.'.sql', 'archive_sha256' => $version->checksum, 'active' => $version->active, 'is_default' => $version->is_default, 'notes' => $version->notes]);
        return view('admin.versions.form', ['template' => $template, 'legacyVersion' => $version]);
    }

    public function store(IkontrolTemplateRequest $request, IkontrolTemplateValidationService $validator, AuditService $audit)
    {
        $template = $this->persist(new IkontrolTemplate(), $request->validated(), $validator);
        $audit->record('create_ikontrol_version', 'Versión iKontrol completa registrada.', $template);
        return redirect()->route('versions.index')->with('success', 'Versión guardada y validada.');
    }

    public function updateTemplate(IkontrolTemplateRequest $request, IkontrolTemplate $template, IkontrolTemplateValidationService $validator, AuditService $audit)
    {
        $this->persist($template, $request->validated(), $validator);
        $audit->record('update_ikontrol_version', 'Versión iKontrol completa actualizada.', $template);
        return redirect()->route('versions.index')->with('success', 'Versión actualizada y validada.');
    }

    public function completeLegacy(IkontrolTemplateRequest $request, IkontrolVersion $version, IkontrolTemplateValidationService $validator, AuditService $audit)
    {
        $template = $this->persist(IkontrolTemplate::firstOrNew(['version' => $version->version]), $request->validated(), $validator);
        $audit->record('complete_ikontrol_version', 'Versión legada completada como paquete desplegable.', $template, ['legacy_version_id' => $version->id]);
        return redirect()->route('versions.index')->with('success', 'Versión existente completada sin duplicarla en el catálogo visible.');
    }

    private function persist(IkontrolTemplate $template, array $data, IkontrolTemplateValidationService $validator): IkontrolTemplate
    {
        $data['active'] = (bool) ($data['active'] ?? false); $data['is_default'] = $data['active'] && (bool) ($data['is_default'] ?? false);
        $candidate = clone $template; $candidate->fill($data);
        try { $validator->validate($candidate); } catch (\Throwable $e) {
            $message = $e->getMessage();
            $field = str_contains($message, 'checksum SQL') ? 'database_sha256' : (str_contains($message, 'checksum ZIP') ? 'archive_sha256' : (str_contains($message, 'SQL') ? 'database_dump_path' : (str_contains($message, 'ZIP') || str_contains($message, 'archivo') ? 'archive_path' : 'version')));
            throw ValidationException::withMessages([$field => $message]);
        }
        return DB::transaction(function () use ($template, $data) { if ($data['is_default']) IkontrolTemplate::query()->when($template->exists, fn ($q) => $q->whereKeyNot($template->id))->update(['is_default' => false]); $template->fill($data)->save(); return $template; });
    }
}
