@extends('layouts/layoutMaster')
@section('title','Configuración')
@section('content')
@include('admin.partials.flash')
<div class="mb-6"><h4 class="mb-1">Configuración</h4><p class="text-body-secondary mb-0">Diagnóstico seguro de infraestructura. Ningún secreto se muestra.</p></div>
@php
 $diagnostic=session('diagnostic');
 $sections=[
  ['cPanel','cpanel','tabler-cloud','primary',[['Host',config('ikontrol.cpanel.host')?:'No configurado'],['Puerto',config('ikontrol.cpanel.port')],['Usuario',config('ikontrol.cpanel.username')?:'No configurado'],['Token',config('ikontrol.cpanel.token')?'Configurado':'No configurado']],'Probar consulta de bases'],
  ['MySQL global','mysql','tabler-database','info',[['Host',config('ikontrol.db.host')?:'No configurado'],['Puerto',config('ikontrol.db.port')],['Usuario',config('ikontrol.db.username')?:'No configurado'],['Password',config('ikontrol.db.password')!==null?'Configurado':'No configurado']],'Probar conexión global'],
  ['Filesystem','filesystem','tabler-folder','warning',[['Root',config('ikontrol.instances_root')?:'No configurado']],'Probar permisos'],
  ['FactuCare Legacy','factucare','tabler-database-search','danger',[['Host',config('fc2.host')?:'No configurado'],['Puerto',config('fc2.port')],['Base',config('fc2.database')?:'No configurada'],['Usuario',config('fc2.username')?:'No configurado'],['Password',config('fc2.password')!==null?'Configurado':'No configurado']],'Probar conexión FactuCare'],
 ];
@endphp
<div class="row g-6">@foreach($sections as [$title,$target,$icon,$color,$rows,$button])
@php $status=$diagnostic&&($diagnostic['target']??null)===$target?($diagnostic['status']??'ERROR'):($configurationStatus[$target]??'NOT_CONFIGURED'); $statusColor=match($status){'CONNECTED'=>'success','ERROR'=>'danger',default=>'secondary'}; @endphp
<div class="col-12 col-lg-4"><div class="card h-100"><div class="card-body d-flex flex-column"><div class="d-flex align-items-center justify-content-between gap-3 mb-5"><div class="d-flex align-items-center gap-3"><div class="avatar"><span class="avatar-initial rounded bg-label-{{ $color }}"><i class="icon-base ti {{ $icon }}"></i></span></div><h5 class="mb-0">{{ $title }}</h5></div><span class="badge bg-label-{{ $statusColor }}">{{ $status }}</span></div><dl class="mb-5">@foreach($rows as [$key,$value])<div class="mb-3"><dt class="small text-body-secondary fw-normal">{{ $key }}</dt><dd class="mb-0 text-break fw-medium">{{ $value }}</dd></div>@endforeach</dl>@if($diagnostic&&($diagnostic['target']??null)===$target&&isset($diagnostic['response_time_ms']))<small class="text-body-secondary mb-3">Respuesta: {{ $diagnostic['response_time_ms'] }} ms</small>@endif<form method="POST" action="{{ route('configuration.test',$target) }}" class="mt-auto">@csrf<button class="btn btn-outline-primary w-100"><i class="icon-base ti tabler-player-play me-2"></i>{{ $button }}</button></form></div></div></div>
@endforeach</div>
@endsection
