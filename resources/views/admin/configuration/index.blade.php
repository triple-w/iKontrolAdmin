@extends('layouts/layoutMaster')
@section('title', 'Configuración')
@section('content')
@include('admin.partials.flash')
<div class="mb-6"><h4 class="mb-1">Configuración</h4><p class="text-body-secondary mb-0">Estado de servicios y conectividad; los secretos nunca se muestran.</p></div>
@php
  $sections = [
    ['cPanel','cpanel','tabler-cloud','primary',[['Host',config('ikontrol.cpanel.host') ?: 'No configurado'],['Usuario',config('ikontrol.cpanel.username')],['Token',config('ikontrol.cpanel.token') ? 'Configurado' : 'No configurado']],'Probar conexión'],
    ['MySQL','mysql','tabler-database','info',[['Host',config('ikontrol.db.host')],['Usuario',config('ikontrol.db.username')]],'Probar conexión'],
    ['Filesystem','filesystem','tabler-folder','warning',[['Root de instalaciones',config('ikontrol.instances_root')]],'Probar permisos'],
  ];
@endphp
<div class="row g-6">@foreach($sections as [$title,$target,$icon,$color,$rows,$button])<div class="col-12 col-lg-4"><div class="card h-100"><div class="card-body d-flex flex-column"><div class="d-flex align-items-center gap-3 mb-5"><div class="avatar"><span class="avatar-initial rounded bg-label-{{ $color }}"><i class="icon-base ti {{ $icon }}"></i></span></div><h5 class="mb-0">{{ $title }}</h5></div><dl class="mb-5">@foreach($rows as [$key,$value])<div class="mb-3"><dt class="small text-body-secondary fw-normal">{{ $key }}</dt><dd class="mb-0 text-break fw-medium">{{ $value }}</dd></div>@endforeach</dl><form method="POST" action="{{ route('configuration.test',$target) }}" class="mt-auto">@csrf<button class="btn btn-outline-primary w-100"><i class="icon-base ti tabler-player-play me-2"></i>{{ $button }}</button></form></div></div></div>@endforeach</div>
@endsection
