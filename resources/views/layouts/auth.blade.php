@php
  Helper::updatePageConfig(['myLayout' => 'blank', 'hasCustomizer' => false, 'displayCustomizer' => false]);
  $configData = Helper::appClasses();
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" class="customizer-hide" dir="{{ $configData['textDirection'] }}"
  data-assets-path="{{ asset('/assets') }}/" data-base-url="{{ url('/') }}"
  data-bs-theme="{{ $configData['theme'] }}" data-template="blank-menu-template">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <meta name="robots" content="noindex, nofollow">
  <title>@yield('title', 'Acceso') | iKontrol Admin</title>
  <link rel="icon" type="image/x-icon" href="{{ asset('assets/img/favicon/favicon.ico') }}">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  @vite(['resources/assets/vendor/fonts/iconify/iconify.css','resources/assets/vendor/libs/node-waves/node-waves.scss','resources/assets/vendor/scss/core.scss','resources/assets/css/demo.css','resources/assets/vendor/scss/pages/page-auth.scss','resources/css/app.css','resources/assets/vendor/js/helpers.js','resources/assets/js/config.js'])
</head>
<body>
  @yield('content')
  @vite(['resources/assets/vendor/libs/jquery/jquery.js','resources/assets/vendor/libs/popper/popper.js','resources/assets/vendor/js/bootstrap.js','resources/assets/vendor/libs/node-waves/node-waves.js','resources/assets/js/main.js','resources/js/app.js'])
</body>
</html>
