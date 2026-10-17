<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\IkontrolTemplateRequest;
use App\Models\{AdminUser, IkontrolRelease, IkontrolTemplate, IkontrolVersion, InstanceUpdateRun};
use App\Services\{AuditService, IkontrolTemplateValidationService};
use App\Services\Versioning\{GoldenInstanceResolver, ReleasePromotionService, ReleaseSyncService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class IkontrolVersionController extends Controller
{
    public function index(IkontrolTemplateValidationService $validator)
    {
        $releases = IkontrolRelease::with(['latestPromotion.actor', 'latestPromotion.updateRun'])->orderByDesc('published_at')->orderByDesc('id')->get();
        $templates = IkontrolTemplate::latest()->get();
        $validity = $templates->mapWithKeys(function (IkontrolTemplate $template) use ($validator) {
            try { $validator->validate($template); return [$template->id => true]; } catch (\Throwable) { return [$template->id => false]; }
        });
        $legacyVersions = IkontrolVersion::whereNotIn('version', $templates->pluck('version'))->latest()->get();
        return view('admin.versions.index', compact('releases', 'templates', 'legacyVersions', 'validity'));
    }

    public function sync(ReleaseSyncService $sync, AuditService $audit)
    {
        try {
            $summary = $sync->sync();
            $audit->record('sync_platform_releases', 'Sincronización del catálogo de releases ejecutada.', 'ikontrol_releases', $summary);
            return back()->with('success', sprintf('Descubiertas: %d · Importadas: %d · Actualizadas: %d · Inválidas: %d', $summary['discovered'], $summary['imported'], $summary['updated'], $summary['invalid']));
        } catch (\Throwable) {
            return back()->with('error', 'No fue posible consultar GitHub. Revise la configuración y vuelva a intentar.');
        }
    }

    public function manifest(IkontrolRelease $release, GoldenInstanceResolver $golden, ReleasePromotionService $promotions)
    {
        $release->load(['promotions.actor', 'promotions.updateRun.instance']);
        try { $goldenInstance = $golden->resolve(); } catch (Throwable) { $goldenInstance = null; }
        $goldenRuns = $goldenInstance
            ? $release->updateRuns()->where('instance_id', $goldenInstance->id)->whereIn('status', ['completed', 'SUCCESS'])->where('release_commit_sha', $release->commit_sha)->where('release_artifact_sha256', $release->artifact_sha256)->latest('id')->get()
            : collect();
        $approvalCurrent = $promotions->hasCurrentApproval($release);

        return view('admin.versions.manifest', compact('release', 'goldenInstance', 'goldenRuns', 'approvalCurrent'));
    }

    public function promoteToStaging(IkontrolRelease $release, Request $request, ReleasePromotionService $promotions, AuditService $audit)
    {
        $data = $request->validate(['instance_update_run_id' => ['required', 'integer', 'exists:instance_update_runs,id'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $actor = $this->actor($request);
        try {
            $event = $promotions->promoteToStaging($release, InstanceUpdateRun::findOrFail($data['instance_update_run_id']), $actor, $data['notes'] ?? null);
            $audit->record('RELEASE_PROMOTED_TO_STAGING', 'Release promovida a STAGING con evidencia de Golden.', $release, ['release_promotion_id' => $event->id, 'instance_update_run_id' => $event->instance_update_run_id]);
            return back()->with('success', 'Release promovida a STAGING.');
        } catch (Throwable $e) {
            $audit->record('RELEASE_PROMOTION_REJECTED', 'Promoción a STAGING rechazada por una regla de dominio.', $release, ['instance_update_run_id' => $data['instance_update_run_id']]);
            return back()->with('error', $this->promotionError($e));
        }
    }

    public function approve(IkontrolRelease $release, Request $request, ReleasePromotionService $promotions, AuditService $audit)
    {
        $data = $request->validate([
            'instance_update_run_id' => ['required', 'integer', 'exists:instance_update_runs,id'],
            'confirmation' => ['required', 'string', Rule::in([(string) $release->release_identifier])],
            'evidence' => ['required', 'array', 'min:1'],
            'evidence.*' => ['required', 'string', 'distinct', Rule::in(ReleasePromotionService::APPROVAL_CHECKLIST)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $actor = $this->actor($request);
        try {
            $event = $promotions->approve($release, InstanceUpdateRun::findOrFail($data['instance_update_run_id']), $actor, $data['confirmation'], $data['evidence'], $data['notes'] ?? null);
            $audit->record('RELEASE_APPROVED', 'Release aprobada manualmente después de validación Golden.', $release, ['release_promotion_id' => $event->id, 'instance_update_run_id' => $event->instance_update_run_id, 'evidence' => $event->evidence]);
            return back()->with('success', 'Release APPROVED para instancias no-Golden.');
        } catch (Throwable $e) {
            $audit->record('RELEASE_APPROVAL_REJECTED', 'Aprobación de release rechazada por una regla de dominio.', $release, ['instance_update_run_id' => $data['instance_update_run_id']]);
            return back()->with('error', $this->promotionError($e));
        }
    }

    public function deprecate(IkontrolRelease $release, Request $request, ReleasePromotionService $promotions, AuditService $audit)
    {
        $data = $request->validate(['confirmation' => ['required', 'string', Rule::in([(string) $release->release_identifier])], 'notes' => ['required', 'string', 'max:2000']]);
        $actor = $this->actor($request);
        try {
            $event = $promotions->deprecate($release, $actor, $data['confirmation'], $data['notes']);
            $audit->record('RELEASE_DEPRECATED', 'Release deprecada; nuevos deployments bloqueados.', $release, ['release_promotion_id' => $event->id]);
            return back()->with('success', 'Release DEPRECATED.');
        } catch (Throwable $e) {
            $audit->record('RELEASE_DEPRECATION_REJECTED', 'Deprecación rechazada por una regla de dominio.', $release);
            return back()->with('error', $this->promotionError($e));
        }
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

    private function actor(Request $request): AdminUser
    {
        $actor = $request->user();
        abort_unless($actor instanceof AdminUser, 403);
        return $actor;
    }

    private function promotionError(Throwable $error): string
    {
        if ($error instanceof RuntimeException) return $error->getMessage();
        report($error);
        return 'La transición no pudo completarse de forma segura.';
    }
}
