@extends('layouts/layoutMaster')
@section('title', $instance->name)
@section('content')
@include('admin.partials.flash')
@php
  $inspection = $instance->latestInspection;
  $schemaColors = ['SCHEMA_RECOGNIZED' => 'success', 'SCHEMA_PARTIAL' => 'warning', 'SCHEMA_UNKNOWN' => 'secondary', 'CONNECTED' => 'info', 'ERROR' => 'danger'];
  $formatBytes = static function (?int $bytes): string {
      if ($bytes === null) return 'No disponible';
      $units = ['B', 'KB', 'MB', 'GB', 'TB']; $value = $bytes; $unit = 0;
      while ($value >= 1024 && $unit < count($units) - 1) { $value /= 1024; $unit++; }
      return number_format($value, $unit === 0 ? 0 : 2).' '.$units[$unit];
  };
@endphp

<div class="card mb-6"><div class="card-body">
  <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-4">
    <div><h4 class="mb-2">{{ $instance->name }}</h4><span class="badge bg-label-primary">{{ $instance->installation_status->value }}</span> <span class="badge bg-label-{{ $instance->last_connection_status === 'CONNECTED' ? 'success' : 'danger' }}">{{ $instance->last_connection_status === 'CONNECTED' ? 'Conectada' : 'Sin conexión' }}</span></div>
    <div class="d-flex flex-wrap gap-2"><form method="POST" action="{{ route('instances.test', $instance) }}">@csrf<button class="btn btn-outline-primary"><i class="icon-base ti tabler-plug-connected me-2"></i>Probar conexión</button></form>@if($instance->installation_status->value==='FAILED' && ($instance->ikontrol_version_id || $instance->ikontrol_template_id))<form method="POST" action="{{ route('provisioning.retry',$instance) }}" onsubmit="return confirm('¿Reintentar desde el paso fallido?')">@csrf<button class="btn btn-warning"><i class="icon-base ti tabler-refresh me-2"></i>Reintentar desde fallo</button></form>@endif @if($instance->ikontrol_template_id && in_array($instance->installation_status->value,['READY_FOR_DOMAIN','FAILED']))<form method="POST" action="{{ route('provisioning.regenerate-configuration',$instance) }}" onsubmit="return confirm('Se regenerará únicamente el .env CodeIgniter y se verificará su conexión. ¿Continuar?')">@csrf<button class="btn btn-outline-warning"><i class="icon-base ti tabler-settings-cog me-2"></i>Regenerar configuración</button></form>@endif @if($instance->installation_status->value==='READY_FOR_DOMAIN')<form method="POST" action="{{ route('provisioning.confirm-domain',$instance) }}">@csrf<button class="btn btn-success"><i class="icon-base ti tabler-world-check me-2"></i>Confirmar dominio</button></form>@endif</div>
  </div><hr>
  <dl class="row mb-0"><dt class="col-sm-3">UUID</dt><dd class="col-sm-9"><code>{{ $instance->uuid }}</code></dd><dt class="col-sm-3">Cliente</dt><dd class="col-sm-9">{{ $instance->client->name }}</dd><dt class="col-sm-3">Carpeta</dt><dd class="col-sm-9 text-break">{{ $instance->absolute_path }}</dd><dt class="col-sm-3">Base</dt><dd class="col-sm-9"><code>{{ $instance->db_name }}</code></dd></dl>
  @if($instance->installation_status->value === 'READY_FOR_DOMAIN')<div class="alert alert-info mt-5">Cree manualmente el subdominio en cPanel y apúntelo al Document Root:<br><code>{{ $instance->absolute_path }}{{ $instance->ikontrol_template_id ? '' : '/public' }}</code><br>Después use “Confirmar dominio”.</div>@endif
</div></div>

@if($instance->template)
<div class="card mb-6"><div class="card-header"><h5 class="mb-1">Operaciones de instalación</h5><p class="text-body-secondary mb-0">Acciones controladas, auditadas y limitadas a esta instancia.</p></div><div class="card-body">
  @if(session('operation_result'))<div class="alert alert-info"><pre class="mb-0">{{ json_encode(session('operation_result'), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre></div>@endif
  <div class="row g-3 mb-5">
  @foreach(['files'=>'Verificar archivos','database'=>'Verificar base de datos','reassign-user'=>'Reasignar usuario MySQL','key'=>'Generar/verificar encryption key','cache'=>'Limpiar cache','migrate'=>'Ejecutar migraciones CodeIgniter','health'=>'Verificar aplicación'] as $operation=>$label)
    <div class="col-sm-6 col-xl-4"><form method="POST" action="{{ route('instances.operations.run',[$instance,$operation]) }}" onsubmit="return confirm('¿Ejecutar {{ $label }} sobre esta instalación?')">@csrf<button class="btn btn-outline-primary w-100">{{ $label }}</button></form></div>
  @endforeach
  </div>
  <div class="row g-5"><div class="col-lg-6"><h6>Crear administrador inicial</h6><form method="POST" action="{{ route('instances.operations.admin',[$instance,'create']) }}">@csrf<div class="mb-3"><input class="form-control" name="name" placeholder="Nombre completo" required></div><div class="mb-3"><input class="form-control" type="email" name="email" placeholder="Email" required></div><div class="mb-3"><input class="form-control" type="password" name="password" placeholder="Contraseña (mínimo 12)" required minlength="12" autocomplete="new-password"></div><div class="mb-3"><input class="form-control" type="password" name="password_confirmation" placeholder="Confirmar contraseña" required autocomplete="new-password"></div><button class="btn btn-primary">Crear administrador</button></form></div>
  <div class="col-lg-6"><h6>Resetear contraseña de administrador</h6><form method="POST" action="{{ route('instances.operations.admin',[$instance,'reset']) }}">@csrf<div class="mb-3"><input class="form-control" type="email" name="email" placeholder="Email del administrador" required></div><div class="mb-3"><input class="form-control" type="password" name="password" placeholder="Nueva contraseña (mínimo 12)" required minlength="12" autocomplete="new-password"></div><div class="mb-3"><input class="form-control" type="password" name="password_confirmation" placeholder="Confirmar contraseña" required autocomplete="new-password"></div><button class="btn btn-outline-warning">Resetear contraseña</button></form></div></div>
</div></div>
<div class="card mb-6"><div class="card-header"><h5 class="mb-0">Template / versión</h5></div><div class="card-body"><dl class="row mb-0"><dt class="col-sm-3">Template</dt><dd class="col-sm-9">{{ $instance->template->name }}</dd><dt class="col-sm-3">App / schema</dt><dd class="col-sm-9">{{ $instance->template->app_version }} / {{ $instance->template->schema_version }}</dd><dt class="col-sm-3">SHA ZIP</dt><dd class="col-sm-9"><code class="text-break">{{ $instance->template->archive_sha256 }}</code></dd><dt class="col-sm-3">SHA SQL</dt><dd class="col-sm-9"><code class="text-break">{{ $instance->template->database_sha256 }}</code></dd></dl></div></div>
@if($instance->is_test && $instance->installation_status->value !== 'READY' && strcasecmp($instance->name,'DOLD')!==0)
<div class="card border-danger mt-5"><div class="card-header"><h6 class="text-danger mb-0">Zona de prueba</h6></div><div class="card-body"><p>Escriba <code>{{ $instance->slug }}</code> para confirmar. Nunca se habilita para READY o DOLD.</p><form method="POST" action="{{ route('instances.operations.destructive',[$instance,'restart']) }}" class="mb-4" onsubmit="return confirm('Se eliminarán carpeta y base y se reprovisionará. ¿Continuar?')">@csrf<div class="input-group"><input class="form-control" name="confirmation" required><button class="btn btn-danger">Reiniciar instalación</button></div></form><form method="POST" action="{{ route('instances.operations.destructive',[$instance,'delete']) }}" onsubmit="return confirm('Esta eliminación es irreversible. ¿Continuar?')">@csrf<div class="mb-3"><input class="form-control" name="confirmation" required></div><div class="d-flex flex-wrap gap-4 mb-3"><label class="form-check"><input class="form-check-input" type="checkbox" name="delete_folder" value="1"> Carpeta</label><label class="form-check"><input class="form-check-input" type="checkbox" name="delete_database" value="1"> Base</label><label class="form-check"><input class="form-check-input" type="checkbox" name="delete_record" value="1"> Registro</label></div><button class="btn btn-outline-danger">Eliminar selección</button></form></div></div>
@endif
@endif

<div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
  <div><h5 class="mb-1">Información de la instancia</h5><p class="text-body-secondary mb-0">Snapshot de la última inspección de solo lectura.</p></div>
  <form method="POST" action="{{ route('instances.inspect', $instance) }}" onsubmit="return confirm('¿Actualizar la información de esta instancia mediante consultas de solo lectura?')">@csrf<button class="btn btn-primary"><i class="icon-base ti tabler-refresh me-2"></i>Actualizar información</button></form>
</div>

@if($inspection)
<div class="row g-6 mb-6">
  <div class="col-sm-6 col-xl-3"><div class="card h-100"><div class="card-body"><small class="text-body-secondary">Empresa / RFC</small><h5 class="mt-2 mb-1">{{ $inspection->company_name ?? $inspection->legal_name ?? 'No disponible' }}</h5><span>{{ $inspection->rfc ?? 'No disponible' }}</span></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="card h-100"><div class="card-body"><small class="text-body-secondary">Versión o schema</small><h5 class="mt-2 text-break">{{ $inspection->app_version ?? $inspection->schema_version ?? 'No disponible' }}</h5></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="card h-100"><div class="card-body"><small class="text-body-secondary">Última actividad</small><h5 class="mt-2">{{ $inspection->last_activity_at?->format('d/m/Y H:i') ?? 'No disponible' }}</h5></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="card h-100"><div class="card-body"><small class="text-body-secondary">Tamaño DB</small><h5 class="mt-2">{{ $formatBytes($inspection->database_size) }}</h5></div></div></div>
</div>

<h5 class="mb-4">Resumen</h5>
<div class="row g-4 mb-6">
  @forelse($inspection->counts ?? [] as $count)
  <div class="col-6 col-md-4 col-xl-3"><div class="card h-100"><div class="card-body"><div class="d-flex align-items-center justify-content-between gap-2"><div><small class="text-body-secondary">{{ $count['label'] }}</small><h3 class="mb-0 mt-1">{{ number_format($count['value']) }}</h3></div><span class="avatar-initial rounded bg-label-primary p-3"><i class="icon-base ti tabler-database"></i></span></div></div></div></div>
  @empty<div class="col-12"><div class="alert alert-secondary mb-0">No se identificaron contadores con suficiente seguridad en este schema.</div></div>
  @endforelse
</div>

<div class="accordion mb-6" id="technical-information"><div class="accordion-item"><h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#technical-collapse">Información técnica</button></h2><div id="technical-collapse" class="accordion-collapse collapse" data-bs-parent="#technical-information"><div class="accordion-body"><div class="table-responsive"><table class="table table-sm"><tbody>
  <tr><th>Host</th><td>{{ data_get($inspection->technical_metadata, 'host', 'No disponible') }}</td></tr><tr><th>Puerto</th><td>{{ data_get($inspection->technical_metadata, 'port', 'No disponible') }}</td></tr><tr><th>Base</th><td><code>{{ data_get($inspection->technical_metadata, 'database', 'No disponible') }}</code></td></tr><tr><th>Tablas</th><td>{{ data_get($inspection->technical_metadata, 'table_count', 0) }}</td></tr><tr><th>Última migration</th><td class="text-break">{{ data_get($inspection->technical_metadata, 'last_migration', 'No disponible') ?? 'No disponible' }}</td></tr><tr><th>Schema status</th><td><span class="badge bg-label-{{ $schemaColors[$inspection->schema_status] ?? 'secondary' }}">{{ $inspection->schema_status }}</span></td></tr><tr><th>Tiempo</th><td>{{ data_get($inspection->technical_metadata, 'duration_ms', 0) }} ms</td></tr><tr><th>Inspeccionado</th><td>{{ $inspection->inspected_at?->format('d/m/Y H:i:s') }}</td></tr>
  </tbody></table></div>@if($inspection->schema_error)<div class="alert alert-danger mt-4 mb-0">{{ $inspection->schema_error }}</div>@endif</div></div></div></div>
@else
<div class="alert alert-info mb-6"><i class="icon-base ti tabler-info-circle me-2"></i>Esta instalación todavía no tiene una inspección. Use “Actualizar información” cuando desee consultar su estructura.</div>
@endif

<div class="card"><div class="card-header"><h5 class="mb-0">Bitácora de instalación</h5></div><div class="table-responsive"><table class="table"><thead><tr><th>Fecha</th><th>Paso</th><th>Estado</th><th>Mensaje</th></tr></thead><tbody>@forelse($instance->installationLogs as $log)<tr><td>{{ $log->created_at }}</td><td>{{ $log->step }}</td><td><span class="badge bg-label-{{ $log->status === 'SUCCESS' ? 'success' : ($log->status === 'FAILED' ? 'danger' : 'info') }}">{{ $log->status }}</span></td><td>{{ $log->message }}</td></tr>@empty<tr><td colspan="4">Sin eventos.</td></tr>@endforelse</tbody></table></div></div>
@endsection
