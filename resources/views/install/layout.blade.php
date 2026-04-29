<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Lintune – Setup</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" crossorigin="anonymous" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" crossorigin="anonymous" />
  <style>
    body { background: #f0f2f5; min-height: 100vh; }
    .install-header { background: #212529; color: #fff; padding: 1rem 0; margin-bottom: 2rem; }
    .install-header .brand { font-weight: 700; font-size: 1.4rem; }
    .install-header .step-text { font-size: .85rem; color: #adb5bd; }
    .step-connector { flex: 1; height: 2px; background: #dee2e6; margin: 0 .5rem; margin-bottom: 1rem; }
    .step-connector.done { background: #198754; }
  </style>
</head>
<body>

<div class="install-header">
  <div class="container">
    <div class="d-flex align-items-center justify-content-between">
      <span class="brand"><i class="bi bi-box me-2"></i>Lintune Setup</span>
      @isset($stepLabel)
        <span class="step-text">{{ $stepLabel }}</span>
      @endisset
    </div>
  </div>
</div>

<div class="container" style="max-width:720px">

  {{-- Step indicator --}}
  @isset($steps)
  <div class="d-flex align-items-center mb-4">
    @foreach($steps as $i => $s)
      <div class="d-flex flex-column align-items-center" style="min-width:80px">
        <div class="rounded-circle d-flex align-items-center justify-content-center mb-1
          {{ $s['state'] === 'done' ? 'bg-success text-white' : ($s['state'] === 'active' ? 'bg-primary text-white' : 'bg-secondary-subtle text-secondary') }}"
          style="width:36px;height:36px;font-size:1rem">
          @if($s['state'] === 'done')
            <i class="bi bi-check-lg"></i>
          @else
            {{ $i + 1 }}
          @endif
        </div>
        <small class="{{ $s['state'] === 'active' ? 'fw-semibold text-primary' : 'text-muted' }}" style="font-size:.72rem;text-align:center">{{ $s['label'] }}</small>
      </div>
      @if(!$loop->last)
        <div class="step-connector {{ $s['state'] === 'done' ? 'done' : '' }}"></div>
      @endif
    @endforeach
  </div>
  @endisset

  @yield('content')

</div>

<div id="loadingOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;flex-direction:column;gap:1rem">
  <div class="spinner-border text-light" style="width:3rem;height:3rem"></div>
  <span class="text-white fw-semibold">Running installation… this may take a few minutes</span>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script>
(function () {
  const overlay = document.getElementById('loadingOverlay');
  document.querySelectorAll('form[data-long-running]').forEach(function (form) {
    form.addEventListener('submit', function () {
      overlay.style.display = 'flex';
    });
  });
})();
</script>
@stack('scripts')
</body>
</html>
