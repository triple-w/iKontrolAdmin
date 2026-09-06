@extends('layouts/layoutMaster')
@section('title','Plantillas iKontrol')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-6"><div><h4 class="mb-1">Plantillas iKontrol</h4><p class="text-body-secondary mb-0">Pares validados de código y base SQL.</p></div><a href="{{ route('templates.create') }}" class="btn btn-primary">Nueva plantilla</a></div>
@include('admin.partials.flash')
<div class="card"><div class="table-responsive"><table class="table"><thead><tr><th>Versión</th><th>Aplicación</th><th>Schema</th><th>Archivos</th><th>Estado</th><th></th></tr></thead><tbody>
@forelse($templates as $template)<tr><td><strong>{{ $template->name }}</strong><br><small>{{ $template->version }}</small></td><td>{{ $template->app_version }}</td><td>{{ $template->schema_version }}</td><td><code>{{ $template->archive_path }}</code><br><code>{{ $template->database_dump_path }}</code></td><td><span class="badge bg-label-{{ $template->active?'success':'secondary' }}">{{ $template->active?'Activa':'Inactiva' }}</span> @if($template->is_default)<span class="badge bg-label-primary">Default</span>@endif</td><td><a href="{{ route('templates.edit',$template) }}" class="btn btn-sm btn-outline-primary">Editar</a></td></tr>@empty<tr><td colspan="6" class="text-center py-6">No hay plantillas registradas.</td></tr>@endforelse
</tbody></table></div><div class="card-footer">{{ $templates->links() }}</div></div>
@endsection
