@extends('layouts/layoutMaster')
@section('title', 'Dashboard')
@section('content')
@include('admin.partials.flash')
@php
  $cards = [
    ['Clientes activos', $stats['clients'], 'tabler-users', 'primary'],
    ['Instalaciones registradas', $stats['instances'], 'tabler-server', 'info'],
    ['Instalaciones conectadas', $stats['connected'], 'tabler-plug-connected', 'success'],
    ['Instalaciones con error', $stats['errors'], 'tabler-alert-triangle', 'danger'],
  ];
@endphp
<div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-6">
  <div><h4 class="mb-1">Dashboard</h4><p class="text-body-secondary mb-0">Resumen general de iKontrol Admin</p></div>
  <a href="{{ route('provisioning.create') }}" class="btn btn-primary"><i class="icon-base ti tabler-plus me-2"></i>Nueva instalación</a>
</div>
<div class="row g-6 mb-6">
  @foreach($cards as [$label,$value,$icon,$color])
    <div class="col-12 col-sm-6 col-xl-3"><div class="card h-100"><div class="card-body d-flex align-items-center justify-content-between gap-4"><div><span class="text-body-secondary d-block mb-2">{{ $label }}</span><h2 class="mb-0">{{ number_format($value) }}</h2></div><div class="avatar avatar-lg"><span class="avatar-initial rounded bg-label-{{ $color }}"><i class="icon-base ti {{ $icon }} icon-26px"></i></span></div></div></div></div>
  @endforeach
</div>
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between"><div><h5 class="mb-1">Últimas instalaciones</h5><small class="text-body-secondary">Actividad reciente registrada</small></div><a href="{{ route('instances.index') }}" class="btn btn-sm btn-outline-primary">Ver todas</a></div>
  <div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Cliente</th><th>Instalación</th><th>Base</th><th>Estado</th><th>Última conexión</th></tr></thead><tbody>
    @forelse($instances as $i)<tr><td class="fw-medium">{{ $i->client->name }}</td><td><a href="{{ route('instances.show',$i) }}">{{ $i->name }}</a></td><td><code class="text-nowrap">{{ $i->db_name }}</code></td><td><span class="badge bg-label-{{ $i->installation_status->value==='FAILED'?'danger':($i->installation_status->value==='READY'?'success':'primary') }}">{{ $i->installation_status->value }}</span></td><td class="text-nowrap">{{ $i->last_connection_at?->diffForHumans() ?? 'Sin probar' }}</td></tr>
    @empty<tr><td colspan="5" class="text-center py-8"><i class="icon-base ti tabler-server-off icon-32px text-body-secondary mb-2"></i><p class="mb-0 text-body-secondary">Sin instalaciones registradas.</p></td></tr>@endforelse
  </tbody></table></div>
</div>
@endsection
