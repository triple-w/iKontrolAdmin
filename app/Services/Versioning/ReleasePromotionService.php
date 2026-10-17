<?php

namespace App\Services\Versioning;

use App\Models\{AdminUser, IkontrolInstance, IkontrolRelease, InstanceUpdateRun, ReleasePromotion};
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ReleasePromotionService
{
    public const APPROVAL_CHECKLIST = ['golden_smoke_test', 'critical_flows_reviewed'];

    public function __construct(private readonly GoldenInstanceResolver $golden) {}

    public function assertDeploymentAllowed(IkontrolInstance $instance, IkontrolRelease $release): void
    {
        $release->refresh();
        if ($release->status !== 'validated') throw new RuntimeException('La release no tiene validación técnica vigente.');

        $status = $release->promotion_status ?: IkontrolRelease::PROMOTION_BUILT;
        if ($status === IkontrolRelease::PROMOTION_DEPRECATED) throw new RuntimeException('La release está deprecada y no admite nuevos deployments.');

        $isGolden = $this->golden->isGolden($instance);
        if (! $isGolden && $status !== IkontrolRelease::PROMOTION_APPROVED) {
            throw new RuntimeException('La release aún no está aprobada para instancias distintas de Golden.');
        }

        if ($status === IkontrolRelease::PROMOTION_APPROVED && ! $this->hasCurrentApproval($release)) {
            throw new RuntimeException('La aprobación no corresponde al commit o artefacto actual.');
        }

        if ($isGolden && ! in_array($status, [
            IkontrolRelease::PROMOTION_BUILT,
            IkontrolRelease::PROMOTION_STAGING,
            IkontrolRelease::PROMOTION_APPROVED,
        ], true)) {
            throw new RuntimeException('El estado de promoción no permite desplegar en Golden.');
        }
    }

    public function assertRunMatchesRelease(InstanceUpdateRun $run, IkontrolRelease $release): void
    {
        if ($run->release_id !== $release->id || $run->to_version !== $release->version) {
            throw new RuntimeException('El run no corresponde a la release seleccionada.');
        }
        if (! $this->sameHash($run->release_commit_sha, $release->commit_sha, 40)) {
            throw new RuntimeException('El commit del run Golden no coincide con la release.');
        }
        if (! $this->sameHash($run->release_artifact_sha256, $release->artifact_sha256, 64)) {
            throw new RuntimeException('El artefacto del run Golden no coincide con la release.');
        }
    }

    public function assertVersionDeploymentAllowed(IkontrolInstance $instance, string $version): IkontrolRelease
    {
        if (! preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $version)) {
            throw new RuntimeException('La versiÃ³n objetivo no tiene un formato vÃ¡lido.');
        }

        $release = IkontrolRelease::query()->where('version', $version)->first();
        if (! $release) {
            throw new RuntimeException('La versiÃ³n objetivo no pertenece al catÃ¡logo interno de releases.');
        }

        $this->assertDeploymentAllowed($instance, $release);

        return $release;
    }

    public function promoteToStaging(IkontrolRelease $release, InstanceUpdateRun $run, AdminUser $actor, ?string $notes = null): ReleasePromotion
    {
        return DB::transaction(function () use ($release, $run, $actor, $notes) {
            $release = IkontrolRelease::query()->lockForUpdate()->findOrFail($release->id);
            if ($release->promotion_status !== IkontrolRelease::PROMOTION_BUILT) throw new RuntimeException('Sólo una release BUILT puede pasar a STAGING.');
            if ($release->status !== 'validated') throw new RuntimeException('La release no tiene validación técnica vigente.');
            $this->assertSuccessfulGoldenRun($run->fresh(), $release);

            $event = $this->record($release, IkontrolRelease::PROMOTION_STAGING, $actor, $run, ['golden_run_validated'], $notes);
            $release->update(['promotion_status' => IkontrolRelease::PROMOTION_STAGING]);

            return $event;
        });
    }

    public function approve(IkontrolRelease $release, InstanceUpdateRun $run, AdminUser $actor, string $confirmation, array $evidence, ?string $notes = null): ReleasePromotion
    {
        if (! auth()->check() || auth()->id() !== $actor->id) throw new RuntimeException('La aprobación requiere un actor autenticado.');

        return DB::transaction(function () use ($release, $run, $actor, $confirmation, $evidence, $notes) {
            $release = IkontrolRelease::query()->lockForUpdate()->findOrFail($release->id);
            if ($release->promotion_status !== IkontrolRelease::PROMOTION_STAGING) throw new RuntimeException('Sólo una release STAGING puede aprobarse.');
            if (! hash_equals((string) $release->release_identifier, $confirmation)) throw new RuntimeException('La confirmación de la release no coincide.');
            if (collect($evidence)->contains(fn ($value) => ! is_string($value))) {
                throw new RuntimeException('La aprobación requiere evidencia o checklist no vacío.');
            }
            $evidence = array_values(array_unique($evidence));
            if ($evidence === [] || array_diff($evidence, self::APPROVAL_CHECKLIST) !== []) {
                throw new RuntimeException('La aprobación requiere evidencia o checklist no vacío.');
            }

            $this->assertSuccessfulGoldenRun($run->fresh(), $release);
            $staging = $release->promotions()->where('to_status', IkontrolRelease::PROMOTION_STAGING)->latest('id')->first();
            if (! $staging || $staging->instance_update_run_id !== $run->id || ! $this->eventMatchesRelease($staging, $release)) {
                throw new RuntimeException('El run no corresponde a la validación STAGING vigente.');
            }

            $event = $this->record($release, IkontrolRelease::PROMOTION_APPROVED, $actor, $run, $evidence, $notes);
            $release->update(['promotion_status' => IkontrolRelease::PROMOTION_APPROVED]);

            return $event;
        });
    }

    public function deprecate(IkontrolRelease $release, AdminUser $actor, string $confirmation, string $notes): ReleasePromotion
    {
        return DB::transaction(function () use ($release, $actor, $confirmation, $notes) {
            $release = IkontrolRelease::query()->lockForUpdate()->findOrFail($release->id);
            if (! in_array($release->promotion_status, [IkontrolRelease::PROMOTION_BUILT, IkontrolRelease::PROMOTION_STAGING, IkontrolRelease::PROMOTION_APPROVED], true)) {
                throw new RuntimeException('La transición solicitada no está permitida.');
            }
            if (! hash_equals((string) $release->release_identifier, $confirmation)) throw new RuntimeException('La confirmación de la release no coincide.');

            $event = $this->record($release, IkontrolRelease::PROMOTION_DEPRECATED, $actor, null, ['reason' => trim($notes)], $notes);
            $release->update(['promotion_status' => IkontrolRelease::PROMOTION_DEPRECATED]);

            return $event;
        });
    }

    public function hasCurrentApproval(IkontrolRelease $release): bool
    {
        if ($release->promotion_status !== IkontrolRelease::PROMOTION_APPROVED) return false;
        $approval = $release->promotions()->where('to_status', IkontrolRelease::PROMOTION_APPROVED)->latest('id')->first();
        if (! $approval || ! $approval->instance_update_run_id || ! $this->eventMatchesRelease($approval, $release)) return false;

        try {
            $this->assertSuccessfulGoldenRun($approval->updateRun, $release);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function assertSuccessfulGoldenRun(?InstanceUpdateRun $run, IkontrolRelease $release): void
    {
        if (! $run || ! in_array(strtoupper((string) $run->status), ['COMPLETED', 'SUCCESS'], true)) {
            throw new RuntimeException('Se requiere un run Golden completado correctamente.');
        }
        if ($run->instance_id !== $this->golden->resolve()->id) throw new RuntimeException('El run no pertenece a la instancia Golden configurada.');
        $this->assertRunMatchesRelease($run, $release);
    }

    private function record(IkontrolRelease $release, string $to, AdminUser $actor, ?InstanceUpdateRun $run, array $evidence, ?string $notes): ReleasePromotion
    {
        return ReleasePromotion::create([
            'ikontrol_release_id' => $release->id,
            'from_status' => $release->promotion_status,
            'to_status' => $to,
            'actor_user_id' => $actor->id,
            'instance_update_run_id' => $run?->id,
            'commit_sha' => strtolower((string) $release->commit_sha),
            'artifact_sha256' => strtolower((string) $release->artifact_sha256),
            'evidence' => $evidence,
            'notes' => $notes !== null ? trim($notes) : null,
            'created_at' => now(),
        ]);
    }

    private function eventMatchesRelease(ReleasePromotion $event, IkontrolRelease $release): bool
    {
        return $this->sameHash($event->commit_sha, $release->commit_sha, 40)
            && $this->sameHash($event->artifact_sha256, $release->artifact_sha256, 64);
    }

    private function sameHash(?string $left, ?string $right, int $length): bool
    {
        return is_string($left) && is_string($right)
            && preg_match('/\A[a-f0-9]{'.$length.'}\z/i', $left)
            && preg_match('/\A[a-f0-9]{'.$length.'}\z/i', $right)
            && hash_equals(strtolower($left), strtolower($right));
    }
}
