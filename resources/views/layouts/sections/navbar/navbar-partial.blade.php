@php
  $admin = auth()->user();
  $initials = $admin
      ? collect(preg_split('/\s+/', trim($admin->name)))->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('')
      : 'IA';
@endphp

@if (!isset($navbarHideToggle))
  <div class="layout-menu-toggle navbar-nav align-items-xl-center me-4 me-xl-0">
    <a class="nav-item nav-link px-0 me-xl-6" href="javascript:void(0)" aria-label="Abrir menú">
      <i class="icon-base ti tabler-menu-2 icon-md"></i>
    </a>
  </div>
@endif

<div class="navbar-nav-right d-flex align-items-center justify-content-end" id="navbar-collapse">
  <ul class="navbar-nav flex-row align-items-center ms-auto">
    @if ($admin)
      <li class="nav-item navbar-dropdown dropdown-user dropdown">
        <a class="nav-link dropdown-toggle hide-arrow p-0" href="javascript:void(0)" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Menú de usuario">
          <div class="avatar avatar-online"><span class="avatar-initial rounded-circle bg-label-primary fw-semibold">{{ $initials }}</span></div>
        </a>
        <ul class="dropdown-menu dropdown-menu-end">
          <li>
            <div class="dropdown-item-text d-flex align-items-center py-3">
              <div class="avatar me-3"><span class="avatar-initial rounded-circle bg-label-primary fw-semibold">{{ $initials }}</span></div>
              <div class="flex-grow-1 overflow-hidden">
                <h6 class="mb-0 text-truncate">{{ $admin->name }}</h6>
                <small class="text-body-secondary text-truncate d-block">{{ $admin->email }}</small>
              </div>
            </div>
          </li>
          <li><div class="dropdown-divider my-1"></div></li>
          <li><a class="dropdown-item" href="{{ route('configuration.index') }}"><i class="icon-base ti tabler-settings me-3 icon-md"></i>Configuración</a></li>
          <li><a class="dropdown-item" href="{{ route('audit.index') }}"><i class="icon-base ti tabler-history me-3 icon-md"></i>Auditoría</a></li>
          <li><div class="dropdown-divider my-1"></div></li>
          <li>
            <form method="POST" action="{{ route('logout') }}">@csrf
              <button type="submit" class="dropdown-item"><i class="icon-base ti tabler-logout me-3 icon-md"></i>Cerrar sesión</button>
            </form>
          </li>
        </ul>
      </li>
    @endif
  </ul>
</div>
