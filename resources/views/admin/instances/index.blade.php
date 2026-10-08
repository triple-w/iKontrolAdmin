@extends('layouts/layoutMaster')
@section('title','Instalaciones')
@section('content')
@include('admin.partials.flash')
@php $colors=['CURRENT'=>'success','UPDATE_AVAILABLE'=>'warning','LEGACY_ADOPTABLE'=>'info','BLOCKED'=>'danger','UNREACHABLE'=>'secondary','NOT_AUDITED'=>'secondary']; $labels=['CURRENT'=>'CURRENT','UPDATE_AVAILABLE'=>'UPDATE AVAILABLE','LEGACY_ADOPTABLE'=>'LEGACY','BLOCKED'=>'BLOCKED','UNREACHABLE'=>'UNREACHABLE','NOT_AUDITED'=>'SIN REVISAR']; @endphp
<div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-6"><div><h4 class="mb-1">Instalaciones</h4><p class="text-body-secondary mb-0">Estado canónico y disponibilidad de actualización</p></div><div class="d-flex flex-column flex-sm-row gap-2"><a class="btn btn-outline-primary" href="{{ route('instances.create') }}"><i class="icon-base ti tabler-database-import me-2"></i>Registrar existente</a><a class="btn btn-primary" href="{{ route('provisioning.create') }}"><i class="icon-base ti tabler-plus me-2"></i>Nueva instalación</a></div></div>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Cliente</th><th>Dominio</th><th>Origen</th><th>Versión detectada</th><th>Versión objetivo</th><th>Estado</th><th>Última revisión</th></tr></thead><tbody>
@forelse($instances as $i)<tr><td class="fw-medium">{{ $i->client->name }}</td><td><a href="{{ route('instances.show',$i) }}">{{ $i->domain ?? $i->url ?? $i->slug }}</a><small class="d-block text-body-secondary">{{ $i->name }}</small></td><td>{{ $i->installation_origin }}</td><td><code>{{ $i->detected_version ?? '—' }}</code></td><td><code>{{ $i->target_version ?? $i->canonical_version ?? '—' }}</code></td><td><span class="badge bg-label-{{ $colors[$i->upgrade_status]??'secondary' }}">{{ $labels[$i->upgrade_status]??$i->upgrade_status }}</span></td><td>{{ $i->last_upgrade_audit_at?->format('d/m/Y H:i') ?? 'Sin revisar' }}</td></tr>
@empty<tr><td colspan="7" class="text-center py-8 text-body-secondary">No hay instalaciones registradas.</td></tr>@endforelse
</tbody></table></div></div><div class="mt-4">{{ $instances->links() }}</div>
@endsection
