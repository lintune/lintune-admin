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

{{-- Stage tracker (only shown when multiple services are being installed) --}}
@if(count($stages) > 1)
<div class="card shadow-sm mb-3">
  <div class="card-body py-3">
    <div class="d-flex align-items-center justify-content-center">
      @foreach($stages as $i => $stage)
        @if($i > 0)
          <div id="connector-{{ $stage }}"
               style="flex:1;height:3px;background:#dee2e6;max-width:80px;transition:background .3s"></div>
        @endif
        <div class="d-flex flex-column align-items-center" style="min-width:80px">
          <div id="circle-{{ $stage }}"
               class="rounded-circle d-flex align-items-center justify-content-center"
               style="width:36px;height:36px;background:#adb5bd;color:#fff;font-weight:700;font-size:.85rem;transition:background .3s;flex-shrink:0">
            {{ $i + 1 }}
          </div>
          <small id="lbl-{{ $stage }}" class="mt-1 text-muted" style="transition:color .3s">
            {{ $stageLabels[$stage] ?? ucfirst($stage) }}
          </small>
        </div>
      @endforeach
    </div>
  </div>
</div>
@endif

<div class="card shadow-sm mb-3">
  <div class="card-header d-flex align-items-center gap-2">
    <span id="statusIcon" class="spinner-border spinner-border-sm text-primary" role="status"></span>
    <span id="statusText" class="fw-semibold">
      Installing {{ $stageLabels[$stages[0]] ?? 'Keycloak' }} — please wait, this may take several minutes…
    </span>
    <div id="actionBox" class="ms-auto d-flex gap-2 d-none">
      <button id="retryBtn" class="btn btn-sm btn-outline-warning d-none">
        <i class="bi bi-arrow-clockwise me-1"></i>Retry
      </button>
      <button id="nextBtn" class="btn btn-sm btn-success d-none">
        <i class="bi bi-arrow-right-circle me-1"></i><span id="nextBtnLabel">Continue</span>
      </button>
    </div>
  </div>
  <div class="card-body p-0">
    <pre id="terminal"
         class="bg-dark text-light m-0 p-3 rounded-bottom"
         style="min-height:200px;max-height:min(500px,55vh);overflow-y:auto;font-size:.78rem;font-family:monospace;white-space:pre-wrap;word-break:break-all"></pre>
  </div>
</div>

<div id="ncWarning" class="alert alert-warning d-none mb-3">
  <i class="bi bi-hourglass-split me-2"></i>
  <strong>Nextcloud can take 10–15 minutes to initialize.</strong>
  AIO pulls several Docker images and runs first-time database setup. The terminal will show progress — please keep this window open.
</div>

<div id="errorBox" class="alert alert-danger d-none mb-3"></div>

@endsection

@push('scripts')
<script>
(function () {
  const stages      = @json($stages);
  const stageLabels = @json($stageLabels);
  const streamUrl   = '{{ route('install.stream', $key) }}';

  const terminal   = document.getElementById('terminal');
  const statusIcon = document.getElementById('statusIcon');
  const statusText = document.getElementById('statusText');
  const errorBox   = document.getElementById('errorBox');
  const ncWarning  = document.getElementById('ncWarning');
  const actionBox  = document.getElementById('actionBox');
  const retryBtn   = document.getElementById('retryBtn');
  const nextBtn    = document.getElementById('nextBtn');
  const nextBtnLbl = document.getElementById('nextBtnLabel');

  let currentStage   = null;
  let countdownTimer = null;

  function appendLine(text) {
    terminal.textContent += text + '\n';
    terminal.scrollTop = terminal.scrollHeight;
  }

  function stageUrl(stage, retry) {
    const params = new URLSearchParams();
    if (stage !== stages[0]) params.set('stage', stage);
    if (retry) params.set('retry', '1');
    const qs = params.toString();
    return qs ? streamUrl + '?' + qs : streamUrl;
  }

  function clearCountdown() {
    if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null; }
  }

  function setCircleStyle(stage, bg, html) {
    const el = document.getElementById('circle-' + stage);
    if (!el) return;
    el.style.background = bg;
    if (html !== undefined) el.innerHTML = html;
  }

  function setLabelStyle(stage, color, bold) {
    const el = document.getElementById('lbl-' + stage);
    if (!el) return;
    el.style.color      = color;
    el.style.fontWeight = bold ? '600' : '';
  }

  function setConnectorStyle(stage, color) {
    const el = document.getElementById('connector-' + stage);
    if (el) el.style.background = color;
  }

  function markActive(stage) {
    setCircleStyle(stage, '#0d6efd');
    setLabelStyle(stage, '#0d6efd', true);
  }

  function markDone(stage) {
    setCircleStyle(stage, '#198754', '<i class="bi bi-check-lg"></i>');
    setLabelStyle(stage, '#198754', true);
    const idx = stages.indexOf(stage);
    if (idx >= 0 && idx + 1 < stages.length) {
      setConnectorStyle(stages[idx + 1], '#198754');
    }
  }

  function markError(stage) {
    setCircleStyle(stage, '#dc3545', '<i class="bi bi-x-lg"></i>');
    setLabelStyle(stage, '#dc3545', true);
  }

  // Start a Netflix-style countdown then auto-advance to the next stage.
  function startCountdown(nextStage, nextLabel, secs) {
    clearCountdown();
    let remaining = secs;

    const updateBtn = () => {
      nextBtnLbl.textContent = 'Continue to ' + nextLabel + ' (' + remaining + 's)';
    };
    updateBtn();

    // "Skip countdown" — proceed immediately
    nextBtn.onclick = function () {
      clearCountdown();
      nextBtnLbl.textContent = 'Continue to ' + nextLabel;
      startStage(nextStage, false);
    };

    countdownTimer = setInterval(function () {
      remaining--;
      if (remaining <= 0) {
        clearCountdown();
        startStage(nextStage, false);
      } else {
        updateBtn();
      }
    }, 1000);
  }

  function startStage(stage, retry) {
    clearCountdown();
    currentStage = stage;
    retry        = !!retry;
    const label  = stageLabels[stage] || stage;

    markActive(stage);
    ncWarning.classList.toggle('d-none', stage !== 'nextcloud');

    if (!retry) {
      terminal.textContent = '';
    }

    statusIcon.className = 'spinner-border spinner-border-sm text-primary';
    statusText.textContent = (retry ? 'Retrying ' : 'Installing ') + label + ' — please wait…';
    errorBox.classList.add('d-none');
    actionBox.classList.add('d-none');
    retryBtn.classList.add('d-none');
    nextBtn.classList.add('d-none');

    const es = new EventSource(stageUrl(stage, retry));

    es.addEventListener('log', function (e) {
      appendLine(JSON.parse(e.data).line);
    });

    es.addEventListener('done', function (e) {
      es.close();
      const data = JSON.parse(e.data);
      markDone(stage);
      ncWarning.classList.add('d-none');

      if (data.redirect) {
        statusIcon.className = 'bi bi-check-circle-fill text-success';
        statusText.textContent = 'All services installed successfully.';
        nextBtnLbl.textContent = 'View Summary';
        nextBtn.classList.remove('d-none');
        nextBtn.onclick = function () { window.location.href = data.redirect; };
        actionBox.classList.remove('d-none');
        return;
      }

      if (data.next_stage) {
        const nextLabel = stageLabels[data.next_stage] || data.next_stage;
        statusIcon.className = 'bi bi-check-circle-fill text-success';
        statusText.textContent = label + ' complete — starting ' + nextLabel + ' shortly…';
        nextBtn.classList.remove('d-none');
        actionBox.classList.remove('d-none');
        startCountdown(data.next_stage, nextLabel, 8);
      }
    });

    es.addEventListener('error', function (e) {
      es.close();
      clearCountdown();
      markError(stage);
      ncWarning.classList.add('d-none');
      statusIcon.className = 'bi bi-x-circle-fill text-danger';
      statusText.textContent = label + ' installation failed.';

      if (e.data) {
        try { errorBox.textContent = JSON.parse(e.data).message; }
        catch (_) { errorBox.textContent = e.data; }
      } else {
        errorBox.textContent = 'Lost connection to server. Check the installation log on the server.';
      }
      errorBox.classList.remove('d-none');

      retryBtn.classList.remove('d-none');
      retryBtn.onclick = function () {
        appendLine('\n--- Retrying ' + label + ' ---\n');
        startStage(stage, true);
      };
      actionBox.classList.remove('d-none');
    });
  }

  startStage(stages[0], false);
})();
</script>
@endpush
