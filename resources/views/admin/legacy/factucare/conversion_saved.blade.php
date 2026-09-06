@extends('layouts/layoutMaster')
@section('title','Plan FC2 → iKontrol')
@section('content')
@include('admin.partials.flash')
<div class="d-flex justify-content-between mb-5"><div><h4>Plan #{{ $plan->id }} · {{ $plan->legacy_rfc }}</h4><p>Usuario FC2 #{{ $plan->legacy_user_id }} · {{ $plan->destination?->name ?? 'Sin destino' }}</p></div><div><span class="badge bg-label-{{ $sourceState==='SOURCE_CHANGED'?'danger':'success' }}">{{ $sourceState }}</span> <span class="badge bg-label-secondary">{{ $plan->status }}</span></div></div>
@if($sourceState==='SOURCE_CHANGED')<div class="alert alert-danger">FactuCare cambió desde la generación de este plan. Regénere el análisis antes de usarlo.</div>@endif
<div class="alert alert-warning"><strong>SIMULACIÓN.</strong> No existe ninguna acción de ejecución de migración.</div>
<div class="card mb-5"><div class="card-body"><pre>{{ json_encode($plan->summary_json,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre></div></div>
<div class="card"><div class="table-responsive"><table class="table"><thead><tr><th>Entidad</th><th>Origen</th><th>Estado</th><th>Acción</th><th>Advertencias</th></tr></thead><tbody>@foreach($plan->items as $item)<tr><td>{{ $item->entity_type }}</td><td>{{ $item->source_table }} #{{ $item->source_id }}</td><td>{{ $item->validation_status }}</td><td>{{ $item->proposed_action }}</td><td>{{ implode(' · ',$item->warnings_json??[]) }}</td></tr>@endforeach</tbody></table></div></div>
<form class="mt-5" method="POST" action="{{ route('legacy.factucare.conversion.plans.regenerate',$plan) }}">@csrf<button class="btn btn-primary">Regenerar plan</button></form>
@endsection