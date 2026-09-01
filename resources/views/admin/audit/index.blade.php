@extends('layouts/layoutMaster')
@section('title', 'Auditoría')
@section('content')
<div class="mb-6"><h4 class="mb-1">Auditoría</h4><p class="text-body-secondary mb-0">Registro de acciones administrativas</p></div>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Entidad</th><th>Descripción</th><th>IP</th></tr></thead><tbody>
@forelse($logs as $l)<tr><td class="text-nowrap"><span class="fw-medium">{{ $l->created_at?->format('d/m/Y') }}</span><small class="d-block text-body-secondary">{{ $l->created_at?->format('H:i:s') }}</small></td><td>{{ $l->adminUser?->name ?? 'Sistema' }}</td><td><span class="badge bg-label-primary">{{ $l->action }}</span></td><td class="text-nowrap">{{ class_basename($l->entity_type) }}@if($l->entity_id) <span class="text-body-secondary">#{{ $l->entity_id }}</span>@endif</td><td style="min-width:16rem">{{ $l->description }}</td><td class="text-nowrap"><code>{{ $l->ip_address ?? '—' }}</code></td></tr>
@empty<tr><td colspan="6" class="text-center py-8 text-body-secondary">No hay eventos de auditoría.</td></tr>@endforelse
</tbody></table></div></div><div class="mt-4">{{ $logs->links() }}</div>
@endsection
