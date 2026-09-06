<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProvisionInstanceRequest;
use App\Models\{Client, IkontrolInstance, IkontrolTemplate, IkontrolVersion};
use App\Services\InstanceProvisioningService;

class ProvisioningController extends Controller
{
    public function create()
    {
        return view('admin.provisioning.create', ['clients' => Client::whereActive(true)->orderBy('name')->get(), 'templates' => IkontrolTemplate::where('active', true)->orderByDesc('is_default')->orderByDesc('id')->get()]);
    }

    public function preflight(ProvisionInstanceRequest $request, InstanceProvisioningService $service)
    {
        $data = $request->validated();
        return response()->json($service->preflight($data['slug'], IkontrolTemplate::find($data['ikontrol_template_id'] ?? null) ?? IkontrolVersion::find($data['ikontrol_version_id'] ?? null)));
    }

    public function dryRun(ProvisionInstanceRequest $request, InstanceProvisioningService $service)
    {
        $data = $request->validated();
        return response()->json($service->dryRun($data['slug'], IkontrolTemplate::find($data['ikontrol_template_id'] ?? null) ?? IkontrolVersion::find($data['ikontrol_version_id'] ?? null)));
    }

    public function store(ProvisionInstanceRequest $request, InstanceProvisioningService $service)
    {
        $instance = $service->provision($request->validated());
        return redirect()->route('instances.show', $instance)->with($instance->installation_status->value === 'FAILED' ? 'error' : 'success', $instance->installation_status->value === 'FAILED' ? 'La preparación falló; revise la bitácora.' : 'Instalación preparada; confirme el dominio.');
    }

    public function retry(IkontrolInstance $instance, InstanceProvisioningService $service)
    {
        $service->retry($instance);
        return back()->with('success', 'Reintento ejecutado desde el paso fallido.');
    }

    public function confirmDomain(IkontrolInstance $instance, InstanceProvisioningService $service)
    {
        $service->confirmDomain($instance);
        return back()->with('success', 'Dominio confirmado.');
    }
}
