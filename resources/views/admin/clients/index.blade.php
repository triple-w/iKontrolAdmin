@extends('layouts/layoutMaster')
@section('title','Clientes')
@section('content')
@include('admin.partials.flash')
<div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-6"><div><h4 class="mb-1">Clientes</h4><p class="text-body-secondary mb-0">Administración del catálogo de clientes</p></div><a class="btn btn-primary" href="{{ route('clients.create') }}"><i class="icon-base ti tabler-user-plus me-2"></i>Nuevo cliente</a></div>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Cliente</th><th>RFC</th><th class="text-center">Instalaciones</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead><tbody>
@forelse($clients as $c)<tr><td><a class="fw-medium" href="{{ route('clients.show',$c) }}">{{ $c->name }}</a>@if($c->legal_name)<small class="d-block text-body-secondary">{{ $c->legal_name }}</small>@endif</td><td class="text-nowrap">{{ $c->rfc ?? '—' }}</td><td class="text-center"><span class="badge bg-label-info rounded-pill">{{ $c->instances_count }}</span></td><td><span class="badge bg-label-{{ $c->active?'success':'secondary' }}">{{ $c->active?'Activo':'Inactivo' }}</span></td><td class="text-end text-nowrap"><a href="{{ route('clients.show',$c) }}" class="btn btn-sm btn-icon btn-text-secondary" title="Ver"><i class="icon-base ti tabler-eye"></i></a><a href="{{ route('clients.edit',$c) }}" class="btn btn-sm btn-icon btn-text-primary" title="Editar"><i class="icon-base ti tabler-edit"></i></a></td></tr>
@empty<tr><td colspan="5" class="text-center py-8"><i class="icon-base ti tabler-users-off icon-32px text-body-secondary"></i><p class="text-body-secondary mb-0 mt-2">No hay clientes registrados.</p></td></tr>@endforelse
</tbody></table></div></div><div class="mt-4">{{ $clients->links() }}</div>
@endsection
