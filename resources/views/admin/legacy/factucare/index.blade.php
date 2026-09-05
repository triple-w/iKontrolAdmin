@extends('layouts/layoutMaster')
@section('title', 'FactuCare Legacy')
@section('content')
@include('admin.partials.flash')
<div class="row justify-content-center">
  <div class="col-12 col-lg-8 col-xl-6">
    <div class="mb-6 text-center"><h4 class="mb-1">FactuCare Legacy</h4><p class="text-body-secondary">Consulta de usuarios y datos históricos en modo de solo lectura.</p></div>
    <div class="card"><div class="card-body p-6 p-md-8">
      <div class="d-flex align-items-center gap-3 mb-6"><span class="avatar-initial rounded bg-label-primary p-3"><i class="icon-base ti tabler-database-search"></i></span><div><h5 class="mb-1">Buscar usuario por RFC</h5><small class="text-body-secondary">El RFC se normaliza automáticamente.</small></div></div>
      <form method="POST" action="{{ route('legacy.factucare.search') }}">@csrf
        <label class="form-label" for="rfc">RFC</label>
        <div class="input-group"><input id="rfc" name="rfc" class="form-control text-uppercase @error('rfc') is-invalid @enderror" value="{{ old('rfc') }}" maxlength="20" autocomplete="off" placeholder="DOLD860620EW7" required><button class="btn btn-primary"><i class="icon-base ti tabler-search me-2"></i>Buscar</button></div>
        @error('rfc')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
      </form>
      <div class="alert alert-info mt-6 mb-0"><i class="icon-base ti tabler-lock me-2"></i>Este módulo no edita, elimina ni migra información de FactuCare.</div>
    </div></div>
  </div>
</div>
@endsection
