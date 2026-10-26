@extends('layouts/layoutMaster')
@section('title','Compatibilidad '.$evaluation->target_version)
@section('content')
@include('admin.partials.flash')
@php
  $summary=$evaluation->summary??[];
  $colors=['COMPATIBLE'=>'success','REVIEW_REQUIRED'=>'warning','BLOCKED'=>'danger','NOT_VERIFIED'=>'secondary','VERIFIED'=>'success'];
@endphp
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-5"><div><h4 class="mb-1">Compatibilidad {{ $instance->name }}</h4><div class="text-body-secondary">Evidencia <code>{{ $evaluation->evidence_id }}</code></div></div><a class="btn btn-outline-secondary" href="{{ route('instances.show',[$instance,'tab'=>'upgrade']) }}">Volver</a></div>
<div class="alert alert-{{ $colors[$evaluation->status]??'secondary' }}"><strong>{{ $evaluation->status }}</strong> · {{ $evaluation->source_version??'origen no verificado' }} → {{ $evaluation->target_version }}. Confianza del bundle: {{ $evaluation->bundle_trust }}. Autoriza deployment: {{ $evaluation->authorizes_deployment?'SÍ':'NO' }}.</div>
<div class="card mb-5"><div class="card-body"><div class="row g-4">
<div class="col-md-4"><small>Versión canónica</small><div>{{ data_get($summary,'versions.canonical','No verificada') }}</div></div>
<div class="col-md-4"><small>Versión registrada en BD</small><div>{{ data_get($summary,'versions.registered','No verificada') }}</div></div>
<div class="col-md-4"><small>Evaluada</small><div>{{ $evaluation->evaluated_at?->format('d/m/Y H:i:s') }}</div></div>
<div class="col-md-6"><small>Contrato SHA-256</small><div class="text-break"><code>{{ $evaluation->contract_sha256??'No verificado' }}</code></div></div>
<div class="col-md-6"><small>Bundle manifest SHA-256</small><div class="text-break"><code>{{ $evaluation->bundle_sha256??'No verificado' }}</code></div></div>
</div></div></div>
<div class="row g-4 mb-5">
@foreach(['files'=>'Archivos','schema'=>'Esquema','runtime'=>'Configuración fiscal'] as $key=>$label)
<div class="col-lg-4"><div class="card h-100"><div class="card-header"><strong>{{ $label }}</strong><span class="badge float-end bg-label-{{ $colors[data_get($summary,$key.'.status')]??'secondary' }}">{{ data_get($summary,$key.'.status','NOT_VERIFIED') }}</span></div><div class="card-body">
@if($key==='files')<div>Coinciden: {{ data_get($summary,'files.matched_count',0) }} / {{ data_get($summary,'files.expected_count',0) }}</div><div>Modificados: {{ count(data_get($summary,'files.modified',[])) }}</div><div>Faltantes: {{ count(data_get($summary,'files.missing',[])) }}</div><div>Extras: {{ count(data_get($summary,'files.extra_in_scope',[])) }}</div>
@elseif($key==='schema')<div>Fingerprint:</div><code class="text-break">{{ data_get($summary,'schema.fingerprint_sha256','No verificado') }}</code><div class="mt-2">Tablas incompletas: {{ count(data_get($summary,'schema.missing_or_incomplete_tables',[])) }}</div>
@else<div>Ambiente: {{ data_get($summary,'runtime.logical_environment','No verificado') }}</div><div>Adaptador: {{ data_get($summary,'runtime.adapter','No verificado') }}</div><div>Coherente: {{ data_get($summary,'runtime.coherent')===true?'Sí':'No / no verificado' }}</div><div>Operativo: {{ data_get($summary,'runtime.operational')===true?'Sí':'No' }}</div>@endif
</div></div></div>@endforeach
</div>
<div class="card mb-5"><div class="card-header"><h5 class="mb-0">Hallazgos</h5></div><div class="card-body">
@foreach(['blockers'=>'Bloqueos','review_required'=>'Revisión requerida','not_verified'=>'No verificado'] as $key=>$label)<h6>{{ $label }}</h6>@forelse(data_get($summary,$key,[]) as $code)<span class="badge bg-label-{{ $key==='blockers'?'danger':($key==='review_required'?'warning':'secondary') }} me-2 mb-2">{{ $code }}</span>@empty<span class="text-body-secondary">Ninguno.</span>@endforelse<hr>@endforeach
</div></div>
<div class="card"><div class="card-header"><h5 class="mb-0">Detalle sanitizado</h5></div><div class="card-body"><pre class="bg-dark text-white p-4 rounded overflow-auto">{{ json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) }}</pre></div></div>
@endsection
