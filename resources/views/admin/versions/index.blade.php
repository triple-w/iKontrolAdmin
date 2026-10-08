@extends('layouts/layoutMaster')
@section('title','Versiones iKontrol')
@section('content')
<div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-6">
  <div><h4 class="mb-1">iKontrol · Versiones</h4><p class="text-body-secondary mb-0">Releases distribuibles descubiertas desde GitHub Releases. No ejecuta actualizaciones.</p></div>
  <form method="POST" action="{{ route('versions.sync') }}">@csrf<button class="btn btn-primary"><i class="icon-base ti tabler-refresh me-2"></i>Buscar actualizaciones</button></form>
</div>
@include('admin.partials.flash')
<div class="card mb-6">
  <div class="table-responsive"><table class="table align-middle"><thead><tr><th>Versión</th><th>Canal</th><th>Estado</th><th>Publicada</th><th>Commit</th><th></th></tr></thead><tbody>
  @forelse($releases as $release)
    @php $statusColors=['validated'=>'success','invalid'=>'danger','discovered'=>'info','deprecated'=>'secondary']; @endphp
    <tr><td><strong>{{ $release->version }}</strong><small class="d-block text-body-secondary">{{ $release->git_tag }}</small></td><td><span class="badge bg-label-{{ $release->channel==='canary'?'warning':'primary' }}">{{ ucfirst($release->channel) }}</span></td><td><span class="badge bg-label-{{ $statusColors[$release->status]??'secondary' }}">{{ ucfirst($release->status) }}</span></td><td>{{ $release->published_at?->format('d/m/Y H:i') ?? '—' }}</td><td><code>{{ substr($release->commit_sha,0,12) }}</code></td><td><div class="d-flex gap-2"><a class="btn btn-sm btn-outline-primary" href="{{ route('versions.releases.manifest',$release) }}">Ver manifest</a><a class="btn btn-sm btn-outline-secondary" href="{{ route('versions.releases.manifest',[$release,'section'=>'compatibility']) }}#compatibility">Ver compatibilidad</a><button class="btn btn-sm btn-secondary" disabled title="Disponible en Fase 2">Actualizar · Fase 2</button></div></td></tr>
  @empty<tr><td colspan="6" class="text-center py-6">No hay releases registradas. Use “Buscar actualizaciones”.</td></tr>@endforelse
  </tbody></table></div>
</div>

<div class="d-flex justify-content-between align-items-center mb-4"><div><h5 class="mb-1">Paquetes de instalación</h5><p class="text-body-secondary mb-0">Catálogo existente usado por el provisioning actual.</p></div><a href="{{ route('versions.create') }}" class="btn btn-outline-primary">Nuevo paquete</a></div>
<div class="card"><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Versión</th><th>Aplicación</th><th>Schema</th><th>Archivos</th><th>Estado</th><th></th></tr></thead><tbody>
@foreach($templates as $template)<tr><td><strong>{{ $template->name }}</strong><br><small>{{ $template->version }}</small></td><td>{{ $template->app_version }}</td><td>{{ $template->schema_version }}</td><td><code>{{ $template->archive_path }}</code><br><code>{{ $template->database_dump_path }}</code></td><td>@if($validity[$template->id])<span class="badge bg-label-success">VALID</span>@else<span class="badge bg-label-danger">INVALID</span>@endif @unless($template->active)<span class="badge bg-label-secondary">INACTIVE</span>@endunless @if($template->is_default)<span class="badge bg-label-primary">DEFAULT</span>@endif</td><td><a href="{{ route('versions.templates.edit',$template) }}" class="btn btn-sm btn-outline-primary">Editar</a></td></tr>@endforeach
@foreach($legacyVersions as $version)<tr><td><strong>{{ $version->name }}</strong><br><small>{{ $version->version }}</small></td><td>{{ $version->version }}</td><td><span class="text-body-secondary">Por completar</span></td><td><code>{{ $version->source_reference }}</code><br><span class="text-body-secondary">SQL pendiente</span></td><td><span class="badge bg-label-danger">INVALID</span> @unless($version->active)<span class="badge bg-label-secondary">INACTIVE</span>@endunless</td><td><a href="{{ route('versions.edit',$version) }}" class="btn btn-sm btn-outline-warning">Completar</a></td></tr>@endforeach
@if($templates->isEmpty() && $legacyVersions->isEmpty())<tr><td colspan="6" class="text-center py-6">No hay paquetes de instalación registrados.</td></tr>@endif
</tbody></table></div></div>
@endsection
