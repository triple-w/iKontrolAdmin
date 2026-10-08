<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\{IkontrolInstance, IkontrolRelease, IkontrolUpgradeAudit, InstanceUpdateRun};
use App\Services\AuditService;
use App\Services\Upgrade\{InstanceUpgradeAuditService, InstanceVersionManagementService};
use App\Services\Versioning\ReleaseDeploymentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class InstanceUpgradeController extends Controller
{
    public function prepareDeployment(IkontrolInstance $instance, IkontrolRelease $release, ReleaseDeploymentService $service, AuditService $audit)
    {
        $audit->record('INSTANCE_RELEASE_PREPARE_STARTED', 'Preparación y dry-run de despliegue solicitados.', $instance, ['release_id' => $release->id, 'version' => $release->version]);
        try {
            $run = $service->prepare($instance, $release);
            $audit->record('INSTANCE_RELEASE_PREPARE_COMPLETED', 'Dry-run de despliegue almacenado.', $instance, ['update_run_id' => $run->id, 'status' => $run->status]);
            return redirect()->route('instances.update-runs.show', [$instance, $run])->with($run->status === 'DEPLOYMENT_READY' ? 'success' : 'error', 'Plan de despliegue generado: '.$run->status.'.');
        } catch (Throwable $e) {
            report($e); $audit->record('INSTANCE_RELEASE_PREPARE_FAILED', 'No fue posible preparar el despliegue.', $instance, ['release_id' => $release->id]);
            return back()->with('error', 'No fue posible preparar el despliegue seguro.');
        }
    }

    public function showDeployment(IkontrolInstance $instance, InstanceUpdateRun $updateRun)
    {
        $this->runBelongsTo($updateRun, $instance); $updateRun->load('release');
        return view('admin.instances.deployment-run', compact('instance', 'updateRun'));
    }

    public function executeDeployment(IkontrolInstance $instance, InstanceUpdateRun $updateRun, Request $request, ReleaseDeploymentService $service, AuditService $audit)
    {
        $this->confirmation($request, $instance); $this->runBelongsTo($updateRun, $instance);
        $audit->record('INSTANCE_RELEASE_DEPLOYMENT_STARTED', 'Despliegue de código y upgrade dirigido confirmados.', $instance, ['update_run_id' => $updateRun->id, 'release_id' => $updateRun->release_id]);
        try {
            $service->execute($instance, $updateRun);
            $audit->record('INSTANCE_RELEASE_DEPLOYMENT_COMPLETED', 'Despliegue, upgrade y reinspección completados.', $instance, ['update_run_id' => $updateRun->id]);
            return redirect()->route('instances.show', [$instance, 'tab' => 'upgrade'])->with('success', 'Release desplegado y validado.');
        } catch (Throwable $e) {
            report($e); $updateRun->refresh();
            $audit->record('INSTANCE_RELEASE_DEPLOYMENT_FAILED', 'El despliegue no se completó; revise rollback y base de datos.', $instance, ['update_run_id' => $updateRun->id, 'rollback_status' => $updateRun->rollback_status, 'database_review_required' => $updateRun->database_review_required]);
            return redirect()->route('instances.update-runs.show', [$instance, $updateRun])->with('error', 'El despliegue falló. Revise el estado de rollback antes de continuar.');
        }
    }
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
            'BLOCKED' => 'La inspección detectó un bloqueo técnico. Revise database-check, versión, plan o adopción.',
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

    private function runBelongsTo(InstanceUpdateRun $run, IkontrolInstance $instance): void
    {
        abort_unless($run->instance_id === $instance->id, 404);
    }
}
