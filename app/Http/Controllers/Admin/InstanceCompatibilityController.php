<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\{IkontrolInstance, InstanceCompatibilityEvaluation};
use App\Services\AuditService;
use App\Services\Compatibility\InstanceCompatibilityService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InstanceCompatibilityController extends Controller
{
    public function store(Request $request, IkontrolInstance $instance, InstanceCompatibilityService $service, AuditService $audit)
    {
        $data = $request->validate([
            'target_version' => ['required', Rule::in($service->targets())],
            'invoice_id' => ['nullable', 'integer', Rule::in([1410])],
        ]);
        $evaluation = $service->evaluate($instance, $data['target_version'], isset($data['invoice_id']) ? (int) $data['invoice_id'] : null, $request->user());
        $audit->record('INSTANCE_COMPATIBILITY_EVALUATED', 'Inspector externo de compatibilidad ejecutado.', $instance, [
            'evidence_id' => $evaluation->evidence_id, 'target_version' => $evaluation->target_version, 'status' => $evaluation->status,
            'bundle_trust' => $evaluation->bundle_trust, 'authorizes_deployment' => $evaluation->authorizes_deployment,
        ]);
        return redirect()->route('instances.compatibility.show', [$instance, $evaluation])
            ->with($evaluation->status === 'COMPATIBLE' ? 'success' : 'error', 'Evaluación completada: '.$evaluation->status.'.');
    }

    public function show(IkontrolInstance $instance, InstanceCompatibilityEvaluation $evaluation)
    {
        abort_unless($evaluation->instance_id === $instance->id, 404);
        return view('admin.instances.compatibility-evaluation', compact('instance', 'evaluation'));
    }
}
