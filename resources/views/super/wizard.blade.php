@extends('super.layout')

@section('page-title', 'Service Setup')

@section('content')

{{-- Step indicator --}}
@php
  $stepMap = [
    'choose'      => 0,
    'server-type' => 1,
    'single'      => 2,
    'multi'       => 2,
    'done'        => 3,
  ];
  $currentIdx = $stepMap[$step] ?? 0;
  $stepLabels = ['Choose', 'Server type', 'Configure', 'Done'];
@endphp

<div class="d-flex align-items-center justify-content-center mb-4">
  @foreach($stepLabels as $i => $label)
    <div class="d-flex flex-column align-items-center" style="min-width:80px">
      <div class="rounded-circle d-flex align-items-center justify-content-center mb-1
        {{ $i < $currentIdx ? 'bg-success text-white' : ($i === $currentIdx ? 'bg-primary text-white' : 'bg-secondary-subtle text-secondary') }}"
        style="width:36px;height:36px">
        @if($i < $currentIdx)
          <i class="bi bi-check-lg"></i>
        @else
          {{ $i + 1 }}
        @endif
      </div>
      <small class="{{ $i === $currentIdx ? 'fw-semibold text-primary' : 'text-muted' }}" style="font-size:.72rem">{{ $label }}</small>
    </div>
    @if($i < count($stepLabels) - 1)
      <div style="flex:1;height:2px;background:{{ $i < $currentIdx ? '#198754' : '#dee2e6' }};margin:0 .5rem;margin-bottom:1rem"></div>
    @endif
  @endforeach
</div>

@if($errors->any())
  <div class="alert alert-danger" style="max-width:640px;margin:0 auto 1rem">{{ $errors->first() }}</div>
@endif

{{-- ── Choose path ──────────────────────────────────────────────────── --}}
@if($step === 'choose')
<div class="card" style="max-width:640px;margin:0 auto">
  <div class="card-body text-center py-5 px-4">
    <i class="bi bi-gear-wide-connected text-primary" style="font-size:2.5rem"></i>
    <h4 class="mt-3 mb-2 fw-bold">Set up Mailcow &amp; Nextcloud</h4>
    <p class="text-muted mb-5">
      These services provide email and file storage for your realms.
      You can install them automatically or configure existing instances.
    </p>
    <div class="row g-3 justify-content-center">
      <div class="col-md-5">
        <a href="{{ route('super.wizard.server-type') }}"
           class="card h-100 text-decoration-none border-primary border-2 text-start p-3 d-block">
          <h6 class="fw-semibold"><i class="bi bi-magic me-1 text-primary"></i>Install for me</h6>
          <p class="text-muted small mb-0">SSH into your server(s) and install automatically.</p>
        </a>
      </div>
      <div class="col-md-5">
        <form method="POST" action="{{ route('super.wizard.skip') }}">
          @csrf
          <button type="submit" class="card h-100 w-100 text-decoration-none text-start p-3 d-block bg-transparent border">
            <h6 class="fw-semibold"><i class="bi bi-wrench me-1 text-secondary"></i>Configure manually</h6>
            <p class="text-muted small mb-0">I'll enter the details in Settings.</p>
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

{{-- ── Server type ──────────────────────────────────────────────────── --}}
@elseif($step === 'server-type')
<div class="mb-3 text-center" style="max-width:640px;margin:0 auto">
  <a href="{{ route('super.wizard') }}" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left me-1"></i>Back
  </a>
</div>
<div class="card" style="max-width:640px;margin:0 auto">
  <div class="card-body py-5 px-4">
    <h5 class="fw-bold text-center mb-4">How are your services arranged?</h5>
    <div class="row g-3 justify-content-center">
      <div class="col-md-5">
        <a href="{{ route('super.wizard.single') }}"
           class="card h-100 text-decoration-none border-primary border-2 text-start p-3 d-block">
          <h6 class="fw-semibold"><i class="bi bi-server me-1 text-primary"></i>One server</h6>
          <p class="text-muted small mb-0">Mailcow and Nextcloud on the same machine.</p>
        </a>
      </div>
      <div class="col-md-5">
        <a href="{{ route('super.wizard.multi') }}"
           class="card h-100 text-decoration-none text-start p-3 d-block">
          <h6 class="fw-semibold"><i class="bi bi-diagram-3 me-1 text-secondary"></i>Separate servers</h6>
          <p class="text-muted small mb-0">Each service on its own machine.</p>
        </a>
      </div>
    </div>
  </div>
</div>

{{-- ── Single server ────────────────────────────────────────────────── --}}
@elseif($step === 'single')
<div class="mb-3" style="max-width:640px;margin:0 auto">
  <a href="{{ route('super.wizard.server-type') }}" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left me-1"></i>Back
  </a>
</div>
<div class="card" style="max-width:640px;margin:0 auto">
  <div class="card-header">
    <h5 class="card-title mb-0"><i class="bi bi-server me-2"></i>Single Server — SSH Access</h5>
  </div>
  <div class="card-body">
    <div class="alert alert-info small mb-4">
      <i class="bi bi-info-circle me-2"></i>
      SSH credentials are used only during this setup and are <strong>never stored</strong>.
      Non-root users will use <code>sudo</code>.
    </div>
    <form method="POST" action="{{ route('super.wizard.single.install') }}" data-long-running>
      @csrf

      <div class="mb-3">
        <label class="form-label fw-semibold">Server</label>
        <div class="form-check mb-1">
          <input class="form-check-input" type="radio" name="ssh_host" id="ssh_local"
                 value="__local__" checked onchange="toggleCustomHost(this.value)">
          <label class="form-check-label" for="ssh_local">
            <strong>This server</strong>
          </label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="radio" name="ssh_host" id="ssh_remote"
                 value="__custom__" onchange="toggleCustomHost(this.value)">
          <label class="form-check-label" for="ssh_remote">Remote IP</label>
        </div>
        <input type="text" id="customHostInput" name="ssh_host_custom" class="form-control mt-2 d-none"
               placeholder="192.168.1.50">
      </div>
      <div class="row g-3 mx-0 mb-4">
        <div class="col-sm-6">
          <label class="form-label">SSH username</label>
          <input type="text" name="ssh_user" class="form-control" value="root" required />
        </div>
        <div class="col-sm-6">
          <label class="form-label">SSH password</label>
          <input type="password" name="ssh_pass" class="form-control" autocomplete="off" required />
        </div>
      </div>

      <h6 class="text-muted mb-3">Services to install</h6>
      <div class="form-check mb-2">
        <input class="form-check-input" type="checkbox" id="mc" name="install_mailcow" value="1"
               onchange="document.getElementById('mcHostname').classList.toggle('d-none', !this.checked)">
        <label class="form-check-label fw-semibold" for="mc">
          <i class="bi bi-envelope me-1 text-primary"></i>Mailcow
        </label>
      </div>
      <div id="mcHostname" class="ms-4 mb-3 d-none">
        <label class="form-label small">Mail server hostname</label>
        <input type="text" name="mailcow_hostname" class="form-control form-control-sm"
               placeholder="mail.company.com" />
        <div class="form-text">Must point to this server's IP.</div>
      </div>

      <div class="form-check mb-4">
        <input class="form-check-input" type="checkbox" id="nc" name="install_nextcloud" value="1">
        <label class="form-check-label fw-semibold" for="nc">
          <i class="bi bi-cloud me-1 text-primary"></i>Nextcloud (AIO)
        </label>
      </div>

      <div class="d-flex justify-content-between">
        <button type="button" class="btn btn-outline-secondary"
                onclick="document.getElementById('skipForm').submit()">Skip</button>
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-play-fill me-1"></i>Install
        </button>
      </div>
    </form>
    <form id="skipForm" method="POST" action="{{ route('super.wizard.skip') }}" class="d-none">@csrf</form>
  </div>
</div>

{{-- ── Multi server ─────────────────────────────────────────────────── --}}
@elseif($step === 'multi')
<div class="mb-3" style="max-width:640px;margin:0 auto">
  <a href="{{ route('super.wizard.server-type') }}" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left me-1"></i>Back
  </a>
</div>
<div class="card" style="max-width:640px;margin:0 auto">
  <div class="card-body">
    <div class="alert alert-info small mb-4">
      <i class="bi bi-info-circle me-2"></i>
      SSH credentials are used only during this setup and are <strong>never stored</strong>.
    </div>
    <form method="POST" action="{{ route('super.wizard.multi.install') }}" data-long-running>
      @csrf

      {{-- Mailcow --}}
      <div class="card mb-3">
        <div class="card-header d-flex align-items-center">
          <h6 class="mb-0 me-auto"><i class="bi bi-envelope me-2 text-primary"></i>Mailcow</h6>
          <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" role="switch" id="mc_toggle"
                   name="install_mailcow" value="1"
                   onchange="document.getElementById('mcFields').classList.toggle('d-none', !this.checked)">
            <label class="form-check-label" for="mc_toggle">Install</label>
          </div>
        </div>
        <div id="mcFields" class="card-body d-none">
          <div class="row g-3 mx-0">
            <div class="col-sm-4">
              <label class="form-label small">Server IP</label>
              <input type="text" name="mc_host" class="form-control form-control-sm" placeholder="10.0.0.20" />
            </div>
            <div class="col-sm-4">
              <label class="form-label small">SSH user</label>
              <input type="text" name="mc_user" class="form-control form-control-sm" value="root" />
            </div>
            <div class="col-sm-4">
              <label class="form-label small">SSH password</label>
              <input type="password" name="mc_pass" class="form-control form-control-sm" autocomplete="off" />
            </div>
            <div class="col-12">
              <label class="form-label small">Mail hostname (e.g. mail.company.com)</label>
              <input type="text" name="mailcow_hostname" class="form-control form-control-sm"
                     placeholder="mail.company.com" />
            </div>
          </div>
        </div>
      </div>

      {{-- Nextcloud --}}
      <div class="card mb-4">
        <div class="card-header d-flex align-items-center">
          <h6 class="mb-0 me-auto"><i class="bi bi-cloud me-2 text-primary"></i>Nextcloud</h6>
          <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" role="switch" id="nc_toggle"
                   name="install_nextcloud" value="1"
                   onchange="document.getElementById('ncFields').classList.toggle('d-none', !this.checked)">
            <label class="form-check-label" for="nc_toggle">Install</label>
          </div>
        </div>
        <div id="ncFields" class="card-body d-none">
          <div class="row g-3 mx-0">
            <div class="col-sm-4">
              <label class="form-label small">Server IP</label>
              <input type="text" name="nc_host" class="form-control form-control-sm" placeholder="10.0.0.30" />
            </div>
            <div class="col-sm-4">
              <label class="form-label small">SSH user</label>
              <input type="text" name="nc_user" class="form-control form-control-sm" value="root" />
            </div>
            <div class="col-sm-4">
              <label class="form-label small">SSH password</label>
              <input type="password" name="nc_pass" class="form-control form-control-sm" autocomplete="off" />
            </div>
          </div>
        </div>
      </div>

      <div class="d-flex justify-content-between">
        <button type="button" class="btn btn-outline-secondary"
                onclick="document.getElementById('skipFormMulti').submit()">Skip</button>
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-play-fill me-1"></i>Install
        </button>
      </div>
    </form>
    <form id="skipFormMulti" method="POST" action="{{ route('super.wizard.skip') }}" class="d-none">@csrf</form>
  </div>
</div>

{{-- ── Done ─────────────────────────────────────────────────────────── --}}
@elseif($step === 'done')
<div class="card text-center" style="max-width:640px;margin:0 auto">
  <div class="card-body py-5">
    <i class="bi bi-check-circle-fill text-success" style="font-size:3rem"></i>
    <h4 class="mt-3 fw-bold">All set!</h4>
    <p class="text-muted mb-4">Your services are configured. Manage connection details any time in Settings.</p>
    <a href="{{ route('super.realms') }}" class="btn btn-primary">
      <i class="bi bi-arrow-right me-1"></i>Go to Realms
    </a>
  </div>
</div>

@if(isset($log) && count($log))
<div class="card mt-3" style="max-width:640px;margin:0 auto">
  <div class="card-header">
    <a href="#wLog" data-bs-toggle="collapse" class="text-muted small text-decoration-none">
      <i class="bi bi-chevron-down me-1"></i>Installation log
    </a>
  </div>
  <div id="wLog" class="collapse">
    <pre class="bg-dark text-light m-0 p-3 rounded-bottom" style="font-size:.75rem;max-height:300px;overflow-y:auto">{{ implode("\n", $log) }}</pre>
  </div>
</div>
@endif
@endif

@push('scripts')
<script>
function toggleCustomHost(val) {
  const inp = document.getElementById('customHostInput');
  if (!inp) return;
  if (val === '__custom__') {
    inp.classList.remove('d-none');
    inp.name = 'ssh_host';
    document.getElementById('ssh_local').name = '';
  } else {
    inp.classList.add('d-none');
    inp.name = 'ssh_host_custom';
    document.getElementById('ssh_local').name = 'ssh_host';
  }
}
</script>
@endpush
@endsection
