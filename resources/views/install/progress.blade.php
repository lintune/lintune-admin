@extends('install.layout')

@php
  $steps = [
    ['label' => 'Welcome',   'state' => 'done'],
    ['label' => 'Configure', 'state' => 'done'],
    ['label' => 'Install',   'state' => 'active'],
    ['label' => 'Done',      'state' => 'pending'],
  ];
  $stepLabel = 'Installing...';
@endphp

@section('content')
<div class="card shadow-sm mb-3">
  <div class="card-header d-flex align-items-center gap-2">
    <span id="statusIcon" class="spinner-border spinner-border-sm text-primary" role="status"></span>
    <span id="statusText" class="fw-semibold">Installing — please wait, this may take several minutes…</span>
  </div>
  <div class="card-body p-0">
    <pre id="terminal"
         class="bg-dark text-light m-0 p-3 rounded-bottom"
         style="min-height:320px;max-height:500px;overflow-y:auto;font-size:.78rem;font-family:monospace;white-space:pre-wrap;word-break:break-all"></pre>
  </div>
</div>

<div id="errorBox" class="alert alert-danger d-none"></div>
@endsection

@push('scripts')
<script>
(function () {
  const terminal   = document.getElementById('terminal');
  const statusIcon = document.getElementById('statusIcon');
  const statusText = document.getElementById('statusText');
  const errorBox   = document.getElementById('errorBox');

  function appendLine(text) {
    terminal.textContent += text + '\n';
    terminal.scrollTop = terminal.scrollHeight;
  }

  const es = new EventSource('{{ route('install.stream', $key) }}');

  es.addEventListener('log', function (e) {
    const data = JSON.parse(e.data);
    appendLine(data.line);
  });

  es.addEventListener('done', function (e) {
    es.close();
    statusIcon.className = 'bi bi-check-circle-fill text-success';
    statusText.textContent = 'Installation complete — redirecting…';
    const data = JSON.parse(e.data);
    window.location.href = data.redirect;
  });

  es.addEventListener('error', function (e) {
    es.close();
    statusIcon.className = 'bi bi-x-circle-fill text-danger';
    statusText.textContent = 'Installation failed.';

    // e.data is only present on named error events from our server
    if (e.data) {
      try {
        const data = JSON.parse(e.data);
        errorBox.textContent = data.message;
      } catch (_) {
        errorBox.textContent = e.data;
      }
    } else {
      errorBox.textContent = 'Lost connection to server. Check the installation log on the server.';
    }
    errorBox.classList.remove('d-none');
  });
})();
</script>
@endpush
