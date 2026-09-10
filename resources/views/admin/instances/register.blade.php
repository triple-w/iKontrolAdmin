@extends('layouts/layoutMaster')
@section('title','Registrar instancia existente')
@section('content')
<h4 class="mb-6">Registrar instancia existente</h4>
<div class="card"><div class="card-body">@include('admin.partials.flash')
<div class="alert alert-info"><i class="icon-base ti tabler-info-circle me-2"></i>El registro solo guarda referencias en iKontrolAdmin. No modifica archivos ni base de datos de la instancia.</div>
<form method="POST" action="{{ route('instances.store') }}">@csrf<div class="row g-5">
<div class="col-md-6"><label class="form-label">Cliente</label><select class="form-select" name="client_id" required><option value="">Seleccione…</option>@foreach($clients as $client)<option value="{{ $client->id }}" @selected(old('client_id')==$client->id)>{{ $client->name }}</option>@endforeach</select></div>
<div class="col-md-6"><label class="form-label">Nombre</label><input class="form-control" name="name" value="{{ old('name') }}" required></div>
<div class="col-md-6"><label class="form-label">Slug</label><input class="form-control" name="slug" value="{{ old('slug') }}" placeholder="cliente_legacy" required></div>
<div class="col-md-6"><label class="form-label">Carpeta (identificador)</label><input class="form-control" name="folder_name" value="{{ old('folder_name') }}" required></div>
<div class="col-12"><label class="form-label">Ruta absoluta del filesystem</label><input class="form-control" name="absolute_path" value="{{ old('absolute_path') }}" placeholder="/home/cuenta/cliente.ikontrol.solutions"></div>
<div class="col-md-6"><label class="form-label">Dominio</label><input class="form-control" name="domain" value="{{ old('domain') }}"></div>
<div class="col-md-6"><label class="form-label">URL</label><input class="form-control" name="url" value="{{ old('url') }}" placeholder="https://cliente.ikontrol.solutions"></div>
<div class="col-md-6"><label class="form-label">Host MySQL</label><input class="form-control" name="db_host" value="{{ old('db_host',config('ikontrol.db.host')) }}"><small class="text-body-secondary">Se usa el usuario global; no se almacenan credenciales nuevas.</small></div>
<div class="col-md-6"><label class="form-label">Base de datos</label><input class="form-control" name="db_name" value="{{ old('db_name') }}" required></div>
<div class="col-md-6"><label class="form-label">Versión objetivo</label><select class="form-select" name="target_version"><option value="{{ config('ikontrol.version_sources.default_target') }}">iKontrol {{ config('ikontrol.version_sources.default_target') }}</option>@foreach($targetVersions as $version)@if($version->version!==config('ikontrol.version_sources.default_target'))<option value="{{ $version->version }}" @selected(old('target_version')===$version->version)>{{ $version->name }} ({{ $version->version }})</option>@endif @endforeach</select></div>
<div class="col-12"><button class="btn btn-primary"><i class="icon-base ti tabler-device-floppy me-2"></i>Registrar instancia existente</button></div>
</div></form></div></div>
@endsection
