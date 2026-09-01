@php
  $containerFooter = ($configData['contentLayout'] ?? 'compact') === 'compact' ? 'container-xxl' : 'container-fluid';
@endphp
<footer class="content-footer footer bg-footer-theme">
  <div class="{{ $containerFooter }}">
    <div class="footer-container d-flex align-items-center justify-content-center py-4">
      <span class="text-body-secondary">© 2026 iKontrol Solutions</span>
    </div>
  </div>
</footer>
