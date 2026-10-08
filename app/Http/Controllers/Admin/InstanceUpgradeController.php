<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\{IkontrolInstance, IkontrolUpgradeAudit};
use App\Services\AuditService;
use App\Services\Upgrade\{InstanceUpgradeAuditService, InstanceVersionManagementService};
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class InstanceUpgradeController extends Controller
{
    public function inspect(IkontrolInstance $instance, InstanceVersionManagementService $service, AuditService $audit)
    {
        $result = $service->inspect($instance);
        $audit->record('INSTANCE_VERSION_INSPECTED', 'Estado canónico de la instancia inspeccionado por Spark.', $instance, [
            'status' => $result['status'], 'current_version' => $result['current_version'], 'canonical_version' => $result['canonical_version'],
        ]);
        $message = match ($result['status']) {
            'CURRENT' => 'Instancia actualizada.',
            'UPDATE_AVAILABLE' => $result['current_version'].' → '.$result['canonical_version'].' disponible.',
            'LEGACY_ADOPTABLE' => 'Instancia legacy compatible con adopción dirigida.',
            'BLOCKED' => 'La inspección detectó un bloqueo. Revise database-check, baseline o adopción.',
            default => 'No fue posible comunicarse con la instancia.',
        };
        return redirect()->route('instances.show', [$instance, 'tab' => 'upgrade'])->with(in_array($result['status'], ['BLOCKED', 'UNREACHABLE'], true) ? 'error' : 'success', $message);
    }

    public function plan(IkontrolInstance $instance, InstanceVersionManagementService $service, AuditService $audit)
    {
        $audit->record('INSTANCE_UPGRADE_PLAN_STARTED', 'Plan dirigido de actualización solicitado.', $instance, ['target_version' => $service->canonicalVersion()]);
        try {
            $result = $service->plan($instance);
            $audit->record('INSTANCE_UPGRADE_PLAN_COMPLETED', 'Plan dirigido de actualización almacenado.', $instance, ['upgrade_audit_id' => $result->id, 'compatibility' => $result->compatibility]);
            return redirect()->route('instances.upgrade-audits.show', [$instance, $result])->with('success', 'Plan de actualización generado.');
        } catch (Throwable $e) {
            report($e);
            return back()->with('error', 'No fue posible generar un plan compatible.');
        }
    }

    public function adoptionPlan(IkontrolInstance $instance, InstanceVersionManagementService $service, AuditService $audit)
    {
        $audit->record('INSTANCE_ADOPTION_PLAN_STARTED', 'Dry-run de adopción solicitado.', $instance);
        try {
            $result = $service->adoptionPlan($instance);
            $audit->record('INSTANCE_ADOPTION_PLAN_COMPLETED', 'Dry-run de adopción almacenado.', $instance, ['upgrade_audit_id' => $result->id, 'compatibility' => $result->compatibility]);
            return redirect()->route('instances.upgrade-audits.show', [$instance, $result])->with('success', 'Dry-run de adopción generado.');
        } catch (Throwable $e) {
            report($e);
            return back()->with('error', 'No fue posible validar la adopción.');
        }
    }

    public function execute(IkontrolInstance $instance, IkontrolUpgradeAudit $upgradeAudit, Request $request, InstanceVersionManagementService $service, AuditService $audit)
    {
        $this->confirmation($request, $instance); $this->belongsTo($upgradeAudit, $instance);
        $audit->record('INSTANCE_UPGRADE_EXECUTION_STARTED', 'Actualización dirigida confirmada; se verificará nuevamente el plan.', $instance, ['upgrade_audit_id' => $upgradeAudit->id, 'target_version' => $upgradeAudit->target_version]);
        try {
            $service->executeUpgrade($instance, $upgradeAudit);
            $audit->record('INSTANCE_UPGRADE_EXECUTION_COMPLETED', 'Actualización dirigida completada y reinspeccionada.', $instance, ['upgrade_audit_id' => $upgradeAudit->id]);
            return redirect()->route('instances.show', [$instance, 'tab' => 'upgrade'])->with('success', 'Instancia actualizada y reinspeccionada.');
        } catch (Throwable $e) {
            report($e); $audit->record('INSTANCE_UPGRADE_EXECUTION_FAILED', 'La actualización dirigida no se completó.', $instance, ['upgrade_audit_id' => $upgradeAudit->id]);
            return back()->with('error', 'La actualización no se completó. La instancia quedó bloqueada para revisión.');
        }
    }

    public function adopt(IkontrolInstance $instance, IkontrolUpgradeAudit $upgradeAudit, Request $request, InstanceVersionManagementService $service, AuditService $audit)
    {
        $this->confirmation($request, $instance); $this->belongsTo($upgradeAudit, $instance);
        $audit->record('INSTANCE_ADOPTION_EXECUTION_STARTED', 'Adopción legacy confirmada; se verificará nuevamente el dry-run.', $instance, ['upgrade_audit_id' => $upgradeAudit->id]);
        try {
            $service->executeAdoption($instance, $upgradeAudit);
            $audit->record('INSTANCE_ADOPTION_EXECUTION_COMPLETED', 'Adopción legacy completada y reinspeccionada.', $instance, ['upgrade_audit_id' => $upgradeAudit->id]);
            return redirect()->route('instances.show', [$instance, 'tab' => 'upgrade'])->with('success', 'Instancia adoptada. Revise ahora la actualización disponible.');
        } catch (Throwable $e) {
            report($e); $audit->record('INSTANCE_ADOPTION_EXECUTION_FAILED', 'La adopción legacy no se completó.', $instance, ['upgrade_audit_id' => $upgradeAudit->id]);
            return back()->with('error', 'La adopción no se completó. La instancia quedó bloqueada para revisión.');
        }
    }

    // Auditoría detallada anterior, conservada para evidencia histórica.
    public function analyze(IkontrolInstance $instance, Request $request, InstanceUpgradeAuditService $service, AuditService $audit)
    {
        $data = $request->validate(['target_version' => ['required', 'string', 'max:50', 'regex:/\A\d+\.\d+\.\d+(?:-[0-9A-Za-z]+(?:\.[0-9A-Za-z]+)*)?\z/']]);
        $audit->record('INSTANCE_UPGRADE_AUDIT_STARTED', 'Auditoría de upgrade iniciada.', $instance, ['target_version' => $data['target_version']]);
        try {
            $result = $service->audit($instance, $data['target_version']);
            $audit->record('INSTANCE_UPGRADE_AUDIT_COMPLETED', 'Auditoría de upgrade completada.', $instance, ['upgrade_audit_id' => $result->id, 'compatibility' => $result->compatibility]);
            return redirect()->route('instances.upgrade-audits.show', [$instance, $result])->with('success', 'Análisis de versión completado sin modificar la instancia.');
        } catch (Throwable $e) {
            report($e); return back()->with('error', 'No fue posible completar la auditoría.');
        }
    }

    public function show(IkontrolInstance $instance, IkontrolUpgradeAudit $upgradeAudit)
    {
        $this->belongsTo($upgradeAudit, $instance); $upgradeAudit->load('items');
        return view('admin.instances.upgrade-audit', compact('instance', 'upgradeAudit'));
    }

    public function dryRun(IkontrolInstance $instance, IkontrolUpgradeAudit $upgradeAudit, InstanceUpgradeAuditService $service, AuditService $audit)
    {
        $this->belongsTo($upgradeAudit, $instance); $plan = $service->regeneratePlan($upgradeAudit->load('items'));
        $audit->record('INSTANCE_UPGRADE_DRY_RUN_GENERATED', 'Dry-run de upgrade generado.', $instance, ['upgrade_audit_id' => $upgradeAudit->id, 'compatibility' => $plan['compatibility']]);
        return back()->with('success', 'Dry-run regenerado. No se ejecutó ningún cambio.');
    }

    private function confirmation(Request $request, IkontrolInstance $instance): void
    {
        $request->validate(['confirmation' => ['required', 'string', Rule::in([$instance->slug])]]);
    }

    private function belongsTo(IkontrolUpgradeAudit $audit, IkontrolInstance $instance): void
    {
        abort_unless($audit->ikontrol_instance_id === $instance->id, 404);
    }
}
