@extends('layouts.auth')
@section('title', 'Iniciar sesión')
@section('content')
<div class="container-xxl"><div class="authentication-wrapper authentication-basic container-p-y"><div class="authentication-inner py-6"><div class="card"><div class="card-body">
  <div class="app-brand justify-content-center mb-4"><a href="{{ route('login') }}" class="app-brand-link" aria-label="iKontrol Admin"><span class="app-brand-logo">@include('_partials.macros', ['width' => 38, 'height' => 27])</span><span class="app-brand-text text-heading fw-bold ms-2">iKontrol Admin</span></a></div>
  <p class="text-center text-body-secondary mb-6">Administración central iKontrol</p>
  <h4 class="mb-2">Bienvenido</h4>
  <p class="mb-6">Administra clientes, instalaciones y servicios iKontrol desde un solo lugar.</p>
  @if ($errors->any())<div class="alert alert-danger" role="alert">@foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
  <form id="formAuthentication" method="POST" action="{{ route('login.store') }}" novalidate>@csrf
    <div class="mb-6 form-control-validation"><label for="email" class="form-label">Email</label><input type="email" class="form-control @error('email') is-invalid @enderror" id="email" name="email" value="{{ old('email') }}" placeholder="nombre@empresa.com" autocomplete="username" required autofocus></div>
    <div class="mb-6 form-password-toggle form-control-validation"><label for="password" class="form-label">Contraseña</label><div class="input-group input-group-merge @error('password') is-invalid @enderror"><input type="password" id="password" class="form-control" name="password" placeholder="············" autocomplete="current-password" required><button class="input-group-text cursor-pointer" type="button" aria-label="Mostrar u ocultar contraseña"><i class="icon-base ti tabler-eye-off"></i></button></div></div>
    <div class="form-check mb-6"><input class="form-check-input" type="checkbox" name="remember" value="1" id="remember" @checked(old('remember'))><label class="form-check-label" for="remember">Mantener sesión</label></div>
    <button class="btn btn-primary d-grid w-100" type="submit">Ingresar</button>
  </form>
</div></div></div></div></div>
@endsection
