@extends('layouts/layoutMaster')
@section('title','Auditoría de actualización')
@section('content')
@include('admin.partials.flash')
@php
  $plan=$upgradeAudit->plan_json??[];
  $directed=in_array($upgradeAudit->status,['PLANNING','PLAN_READY','PLAN_BLOCKED','ADOPTION_PLANNING','ADOPTION_READY','ADOPTION_BLOCKED','COMPLETED','ADOPTED','FAILED'],true) && $upgradeAudit->items->isEmpty();
  $colors=['COMPATIBLE'=>'success','BLOCKED'=>'danger','MANUAL_REVIEW'=>'warning','INCOMPATIBLE'=>'danger'];
@endphp
<div class="d-flex justify-content-between align-items-start mb-6"><div><h4>Auditoría · {{ $instance->name }}</h4><p class="mb-0">{{ $upgradeAudit->source_version ?? 'Legacy' }} → iKontrol {{ $upgradeAudit->target_version }}</p></div><div class="text-end"><span class="badge fs-6 bg-label-{{ $colors[$upgradeAudit->compatibility]??'secondary' }}">{{ $upgradeAudit->compatibility ?? $upgradeAudit->status }}</span><div class="small mt-2">{{ $upgradeAudit->status }}</div></div></div>

@if($directed)
<div class="alert alert-info"><strong>Plan Spark:</strong> esta evidencia fue devuelta por los comandos versionados de la propia instancia. Admin no ejecutó SQL directo ni <code>php spark migrate</code>.</div>
<div class="card mb-6"><div class="card-header"><h5 class="mb-0">Resultado</h5></div><div class="card-body"><pre class="bg-dark text-white p-4 rounded overflow-auto mb-0">{{ json_encode($plan,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) }}</pre></div></div>
@if($upgradeAudit->status==='PLAN_READY' && $upgradeAudit->compatibility==='COMPATIBLE' && $instance->upgrade_status==='UPDATE_AVAILABLE' && $upgradeAudit->target_version===$instance->canonical_version)
<div class="card border-warning"><div class="card-header"><h5 class="mb-0">Actualizar instancia</h5></div><div class="card-body"><p>Se volverá a ejecutar el plan antes de actualizar. Escriba <code>{{ $instance->slug }}</code> para confirmar.</p><form method="POST" action="{{ route('instances.upgrade.execute',[$instance,$upgradeAudit]) }}" onsubmit="return confirm('¿Ejecutar la actualización dirigida de esta instancia?')">@csrf<div class="input-group"><input class="form-control" name="confirmation" required autocomplete="off"><button class="btn btn-warning">Actualizar instancia</button></div></form></div></div>
@elseif($upgradeAudit->status==='ADOPTION_READY' && $upgradeAudit->compatibility==='COMPATIBLE' && $instance->upgrade_status==='LEGACY_ADOPTABLE')
<div class="card border-info"><div class="card-header"><h5 class="mb-0">Adoptar instancia legacy</h5></div><div class="card-body"><p>La adopción no actualiza automáticamente. Escriba <code>{{ $instance->slug }}</code> para confirmar.</p><form method="POST" action="{{ route('instances.adoption.execute',[$instance,$upgradeAudit]) }}" onsubmit="return confirm('¿Adoptar esta instancia al baseline versionado?')">@csrf<div class="input-group"><input class="form-control" name="confirmation" required autocomplete="off"><button class="btn btn-info">Adoptar instancia</button></div></form></div></div>
@endif
@else
<div class="alert alert-info"><strong>Auditoría histórica de solo lectura:</strong> no ejecuta migraciones, SQL de cambio, comandos mutables ni reemplazo de archivos.</div>
<div class="row g-4 mb-6">@foreach(['DATABASE'=>'Base de datos','SETTINGS'=>'Settings','FILES'=>'Archivos','FISCAL'=>'Fiscal','ENVIRONMENT'=>'Entorno','FRAMEWORK'=>'Framework'] as $key=>$label)@php $summary=data_get($plan,"categories.$key",[]);$sev=$summary['severity']??'INFO'; @endphp<div class="col-md-6 col-xl-4"><div class="card h-100"><div class="card-header d-flex justify-content-between"><strong>{{ $label }}</strong><span class="badge bg-label-{{ $sev==='CRITICAL'?'danger':($sev==='WARNING'?'warning':'success') }}">{{ $sev==='INFO'?'READY':$sev }}</span></div><div class="card-body">{{ $summary['total']??0 }} verificaciones @foreach($summary['statuses']??[] as $status=>$count)<div>{{ $status }}: {{ $count }}</div>@endforeach</div></div></div>@endforeach</div>
<div class="card mb-6"><div class="card-header"><h5>Plan</h5></div><div class="card-body"><h6>Preservar</h6><ul>@foreach($plan['preserve']??[] as $value)<li>{{ $value }}</li>@endforeach</ul><h6>Riesgos</h6><ul>@forelse($plan['reasons']??[] as $value)<li>{{ $value }}</li>@empty<li>Sin conflictos críticos detectados.</li>@endforelse</ul><form method="POST" action="{{ route('instances.upgrade-audits.dry-run',[$instance,$upgradeAudit]) }}">@csrf<button class="btn btn-primary">Regenerar Dry-Run histórico</button></form></div></div>
<div class="card"><div class="card-header"><h5>Evidencia</h5></div><div class="table-responsive"><table class="table"><thead><tr><th>Categoría</th><th>Objeto</th><th>Estado</th><th>Severidad</th><th>Actual</th><th>Esperado</th></tr></thead><tbody>@foreach($upgradeAudit->items as $item)<tr><td>{{ $item->category }}</td><td>{{ $item->object_name }}</td><td>{{ $item->status }}</td><td>{{ $item->severity }}</td><td class="text-break"><small>{{ $item->current_value }}</small></td><td class="text-break"><small>{{ $item->expected_value }}</small></td></tr>@endforeach</tbody></table></div></div>
@endif
@endsection
