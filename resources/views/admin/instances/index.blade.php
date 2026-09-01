@extends('layouts/layoutMaster')
@section('title','Instalaciones')
@section('content')
@include('admin.partials.flash')
<div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-6"><div><h4 class="mb-1">Instalaciones</h4><p class="text-body-secondary mb-0">Instancias iKontrol registradas y su conectividad</p></div><div class="d-flex flex-column flex-sm-row gap-2"><a class="btn btn-outline-primary" href="{{ route('instances.create') }}"><i class="icon-base ti tabler-database-import me-2"></i>Registrar existente</a><a class="btn btn-primary" href="{{ route('provisioning.create') }}"><i class="icon-base ti tabler-plus me-2"></i>Nueva instalación</a></div></div>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Cliente</th><th>Instalación</th><th>Carpeta</th><th>Base</th><th>Conexión</th></tr></thead><tbody>
@forelse($instances as $i)<tr><td class="fw-medium">{{ $i->client->name }}</td><td><a href="{{ route('instances.show',$i) }}">{{ $i->name }}</a><small class="d-block text-body-secondary">{{ $i->slug }}</small></td><td><span class="text-nowrap">{{ $i->folder_name }}</span></td><td><code class="text-nowrap">{{ $i->db_name }}</code></td><td><span class="badge bg-label-{{ $i->last_connection_status==='CONNECTED'?'success':($i->last_connection_status==='ERROR'?'danger':'secondary') }}"><i class="icon-base ti {{ $i->last_connection_status==='CONNECTED'?'tabler-circle-check':'tabler-circle-x' }} me-1"></i>{{ $i->last_connection_status==='CONNECTED'?'Conectada':($i->last_connection_status==='ERROR'?'Error':'Sin probar') }}</span></td></tr>
@empty<tr><td colspan="5" class="text-center py-8 text-body-secondary">No hay instalaciones registradas.</td></tr>@endforelse
</tbody></table></div></div><div class="mt-4">{{ $instances->links() }}</div>
@endsection
