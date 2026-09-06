<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\IkontrolTemplateRequest;
use App\Models\IkontrolTemplate;
use App\Services\{AuditService, IkontrolTemplateValidationService};
use Illuminate\Support\Facades\DB;

class IkontrolTemplateController extends Controller
{
    public function index() { return view('admin.templates.index', ['templates' => IkontrolTemplate::latest()->paginate(25)]); }
    public function create() { return view('admin.templates.form', ['template' => new IkontrolTemplate()]); }
    public function edit(IkontrolTemplate $template) { return view('admin.templates.form', compact('template')); }

    public function store(IkontrolTemplateRequest $request, IkontrolTemplateValidationService $validator, AuditService $audit)
    {
        $template = $this->persist(new IkontrolTemplate(), $request->validated(), $validator);
        $audit->record('create_ikontrol_template', 'Plantilla iKontrol registrada.', $template);
        return redirect()->route('templates.index')->with('success', 'Plantilla registrada y validada.');
    }

    public function update(IkontrolTemplateRequest $request, IkontrolTemplate $template, IkontrolTemplateValidationService $validator, AuditService $audit)
    {
        $this->persist($template, $request->validated(), $validator);
        $audit->record('update_ikontrol_template', 'Plantilla iKontrol actualizada.', $template);
        return redirect()->route('templates.index')->with('success', 'Plantilla actualizada y validada.');
    }

    private function persist(IkontrolTemplate $template, array $data, IkontrolTemplateValidationService $validator): IkontrolTemplate
    {
        $data['active'] = (bool) ($data['active'] ?? false);
        $data['is_default'] = $data['active'] && (bool) ($data['is_default'] ?? false);
        $candidate = clone $template; $candidate->fill($data); $validator->validate($candidate);
        return DB::transaction(function () use ($template, $data) {
            if ($data['is_default']) IkontrolTemplate::query()->whereKeyNot($template->getKey())->update(['is_default' => false]);
            $template->fill($data)->save(); return $template;
        });
    }
}
