@extends('layouts/layoutMaster')
@section('title','Manifest '.$release->version)
@section('content')
@php
  $promotionColors=['BUILT'=>'info','STAGING'=>'warning','APPROVED'=>'success','DEPRECATED'=>'secondary'];
  $stagingPromotion=$release->promotions->where('to_status','STAGING')->sortByDesc('id')->first();
@endphp
<div class="d-flex justify-content-between align-items-center mb-6"><div><h4 class="mb-1">Release {{ $release->version }}</h4><p class="text-body-secondary mb-0">{{ $release->source_repository }} · {{ $release->git_tag }}</p></div><a class="btn btn-outline-secondary" href="{{ route('versions.index') }}">Volver</a></div>
@include('admin.partials.flash')

<div class="row g-6 mb-6">
  <div class="col-md-3"><div class="card h-100"><div class="card-body"><small>Validación técnica</small><div class="mt-2"><span class="badge bg-label-{{ $release->status==='validated'?'success':($release->status==='invalid'?'danger':'secondary') }}">{{ strtoupper($release->status) }}</span></div></div></div></div>
  <div class="col-md-3"><div class="card h-100"><div class="card-body"><small>Promoción operativa</small><div class="mt-2"><span class="badge bg-label-{{ $promotionColors[$release->promotion_status]??'secondary' }}">{{ $release->promotion_status }}</span></div></div></div></div>
  <div class="col-md-3"><div class="card h-100"><div class="card-body"><small>Commit</small><div class="mt-2 text-break"><code>{{ $release->commit_sha }}</code></div></div></div></div>
  <div class="col-md-3"><div class="card h-100"><div class="card-body"><small>Artifact SHA-256</small><div class="mt-2 text-break"><code>{{ $release->artifact_sha256 ?? 'No disponible' }}</code></div></div></div></div>
</div>

@if($release->promotion_status==='APPROVED' && !$approvalCurrent)<div class="alert alert-danger">La aprobación histórica no coincide con el commit o artifact SHA-256 actual. Esta release está bloqueada para cualquier nuevo deployment.</div>@endif
@if(!$goldenInstance)<div class="alert alert-warning"><code>IKONTROL_GOLDEN_INSTANCE_ID</code> no identifica una instancia existente. Todas las promociones y deployments quedan bloqueados de forma segura.</div>@else<div class="alert alert-info">Golden oficial: <strong>{{ $goldenInstance->name }}</strong> · ID {{ $goldenInstance->id }} · {{ $goldenInstance->domain ?? $goldenInstance->slug }}</div>@endif

<div class="row g-6">
  <div class="col-lg-8">
    <div class="card mb-6"><div class="card-header"><h5 class="mb-0">Manifest validado</h5></div><div class="card-body"><pre class="bg-dark text-white p-4 rounded overflow-auto mb-0">{{ json_encode($release->manifest_json, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) }}</pre></div></div></div>
    <div class="card"><div class="card-header"><h5 class="mb-0">Historial append-only de promoción</h5></div><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Fecha</th><th>Transición</th><th>Actor</th><th>Golden run</th><th>Evidencia</th></tr></thead><tbody>
      @forelse($release->promotions->sortByDesc('id') as $promotion)
        <tr><td>{{ $promotion->created_at?->format('d/m/Y H:i:s') }}</td><td><span class="badge bg-label-secondary">{{ $promotion->from_status ?? '—' }}</span> → <span class="badge bg-label-{{ $promotionColors[$promotion->to_status]??'secondary' }}">{{ $promotion->to_status }}</span></td><td>{{ $promotion->actor?->name ?? 'Automática' }}</td><td>@if($promotion->updateRun)<code>#{{ $promotion->updateRun->id }}</code><small class="d-block">{{ $promotion->updateRun->instance?->name }}</small>@else—@endif</td><td><small>{{ $promotion->evidence ? implode(', ',array_values($promotion->evidence)) : '—' }}</small>@if($promotion->notes)<div class="text-body-secondary">{{ $promotion->notes }}</div>@endif</td></tr>
      @empty<tr><td colspan="5" class="text-center py-5">La release todavía no tiene transiciones de promoción.</td></tr>@endforelse
    </tbody></table></div></div>
  </div>

  <div class="col-lg-4" id="compatibility">
    <div class="card mb-6"><div class="card-header"><h5 class="mb-0">Compatibilidad</h5></div><div class="card-body"><p><strong>Canal:</strong> {{ ucfirst($release->channel) }}</p><p><strong>Desde:</strong></p><ul>@forelse(data_get($release->manifest_json,'from_versions',[]) as $version)<li><code>{{ $version }}</code></li>@empty<li>Ninguna versión declarada</li>@endforelse</ul><p class="small text-body-secondary mb-0">BUILT y STAGING sólo pueden desplegarse en Golden. Las demás instancias requieren APPROVED vigente.</p></div></div>
    @if($release->validation_errors)<div class="alert alert-danger"><strong>Errores de validación</strong><ul class="mb-0">@foreach($release->validation_errors as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <div class="card mb-6"><div class="card-body"><dl class="mb-0"><dt>Release ID</dt><dd>{{ $release->release_identifier ?? 'No disponible' }}</dd><dt>Referencia</dt><dd><code>{{ $release->source_ref ?? $release->git_tag }}</code></dd><dt>SHA-256 del manifest</dt><dd class="text-break"><code>{{ $release->manifest_hash ?? 'No disponible' }}</code></dd><dt>Verificación</dt><dd>{{ strtoupper($release->artifact_verification_status ?? 'unverified') }}</dd><dt>Descubierta</dt><dd>{{ $release->discovered_at?->format('d/m/Y H:i:s') }}</dd></dl></div></div>

    @if($release->status==='validated' && $release->promotion_status==='BUILT')
      <div class="card border-info mb-6"><div class="card-body"><h5>Promover a STAGING</h5><p class="small">Requiere un deployment Golden completado con este commit y artefacto.</p>@if($goldenRuns->isNotEmpty())<form method="POST" action="{{ route('versions.releases.promote-staging',$release) }}">@csrf<label class="form-label">Golden run</label><select class="form-select mb-3" name="instance_update_run_id" required>@foreach($goldenRuns as $run)<option value="{{ $run->id }}">#{{ $run->id }} · {{ $run->finished_at?->format('d/m/Y H:i') }}</option>@endforeach</select><label class="form-label">Notas</label><textarea class="form-control mb-3" name="notes" maxlength="2000"></textarea><button class="btn btn-info w-100">Promover a STAGING</button></form>@else<div class="alert alert-warning mb-0">No existe un Golden run válido para esta release.</div>@endif</div></div>
    @endif

    @if($release->status==='validated' && $release->promotion_status==='STAGING' && $stagingPromotion)
      <div class="card border-success mb-6"><div class="card-body"><h5>Aprobar release</h5><p class="small">La aprobación quedará vinculada al Golden run #{{ $stagingPromotion->instance_update_run_id }}.</p><form method="POST" action="{{ route('versions.releases.approve',$release) }}">@csrf<input type="hidden" name="instance_update_run_id" value="{{ $stagingPromotion->instance_update_run_id }}"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="evidence[]" value="golden_smoke_test" id="golden-smoke" required><label class="form-check-label" for="golden-smoke">Smoke test Golden validado</label></div><div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="evidence[]" value="critical_flows_reviewed" id="critical-flows"><label class="form-check-label" for="critical-flows">Flujos críticos revisados</label></div><label class="form-label">Confirmar Release ID</label><input class="form-control mb-3" name="confirmation" autocomplete="off" placeholder="{{ $release->release_identifier }}" required><label class="form-label">Notas / evidencia</label><textarea class="form-control mb-3" name="notes" maxlength="2000"></textarea><button class="btn btn-success w-100">Marcar APPROVED</button></form></div></div>
    @endif

    @if(in_array($release->promotion_status,['BUILT','STAGING','APPROVED'],true))
      <div class="card border-danger"><div class="card-body"><h5>Deprecar release</h5><form method="POST" action="{{ route('versions.releases.deprecate',$release) }}" onsubmit="return confirm('¿Bloquear nuevos deployments de esta release?')">@csrf<label class="form-label">Confirmar Release ID</label><input class="form-control mb-3" name="confirmation" autocomplete="off" required><label class="form-label">Motivo</label><textarea class="form-control mb-3" name="notes" maxlength="2000" required></textarea><button class="btn btn-outline-danger w-100">Marcar DEPRECATED</button></form></div></div>
    @endif
  </div>
</div>
@endsection
