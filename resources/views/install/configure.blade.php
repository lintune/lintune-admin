@extends('install.layout')

@php
  $steps = [
    ['label' => 'Welcome',     'state' => 'done'],
    ['label' => 'Server type', 'state' => 'done'],
    ['label' => 'Configure',   'state' => 'active'],
    ['label' => 'Install',     'state' => 'pending'],
    ['label' => 'Done',        'state' => 'pending'],
  ];
  $stepLabel = 'Step 3 of 5';
@endphp

@section('content')
<div class="mb-3">
  <a href="{{ route('install.server-type') }}" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left me-1"></i>Back
  </a>
</div>

@if ($errors->any())
  <div class="alert alert-danger">
    <i class="bi bi-exclamation-triangle me-2"></i>{{ $errors->first() }}
  </div>
@endif

<form method="POST" action="{{ route('install.run') }}" data-long-running>
  @csrf
  <input type="hidden" name="server_type" value="{{ $type }}">

  {{-- ── Single server ─────────────────────────────────────────────────── --}}
  @if($type === 'single')

  <div class="card shadow-sm mb-4">
    <div class="card-header">
      <h5 class="card-title mb-0"><i class="bi bi-server me-2"></i>Keycloak — Server &amp; SSH Access</h5>
    </div>
    <div class="card-body">
      <div class="alert alert-info small mb-4">
        <i class="bi bi-info-circle me-2"></i>
        SSH credentials are used only during this setup session and are <strong>never stored</strong>.
        If the username is not <code>root</code>, <code>sudo</code> will be used.
      </div>

      <div class="mb-3">
        <label class="form-label fw-semibold">Server</label>
        <div class="form-check mb-2">
          <input class="form-check-input" type="radio" name="ssh_host" id="ssh_localhost"
                 value="__local__" {{ old('ssh_host', '__local__') === '__local__' ? 'checked' : '' }}
                 onchange="toggleHostField(this.value)">
          <label class="form-check-label" for="ssh_localhost">
            <strong>This server</strong> <small class="text-muted">(same machine as Lintune admin)</small>
          </label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="radio" name="ssh_host" id="ssh_remote"
                 value="__custom__" {{ old('ssh_host', '__local__') !== '__local__' && old('ssh_host') ? 'checked' : '' }}
                 onchange="toggleHostField(this.value)">
          <label class="form-check-label" for="ssh_remote">Remote IP address</label>
        </div>
        <input type="text" id="customHost" name="ssh_host_custom" class="form-control mt-2 {{ old('ssh_host') && old('ssh_host') !== '__local__' ? '' : 'd-none' }}"
               placeholder="192.168.1.50"
               value="{{ old('ssh_host') !== '__local__' ? old('ssh_host') : '' }}">
      </div>

      <div class="row g-3 mx-0">
        <div class="col-sm-6">
          <label class="form-label">SSH username</label>
          <input type="text" name="ssh_user" class="form-control"
                 value="{{ old('ssh_user', 'root') }}" required />
        </div>
        <div class="col-sm-6">
          <label class="form-label">SSH password</label>
          <input type="password" name="ssh_pass" class="form-control"
                 autocomplete="off" required />
        </div>
      </div>
    </div>
  </div>

  <div class="card shadow-sm mb-4">
    <div class="card-header">
      <h5 class="card-title mb-0"><i class="bi bi-shield-lock me-2"></i>Keycloak Configuration</h5>
    </div>
    <div class="card-body">
      <div class="row g-3 mx-0">
        <div class="col-sm-4">
          <label class="form-label">Port</label>
          <input type="number" name="kc_port" class="form-control"
                 value="{{ old('kc_port', 8080) }}" min="1" max="65535" required />
          <div class="form-text">Default: 8080</div>
        </div>
      </div>
    </div>
  </div>

  <div class="card shadow-sm mb-4">
    <div class="card-header">
      <h5 class="card-title mb-0"><i class="bi bi-grid me-2"></i>Additional Services (optional)</h5>
    </div>
    <div class="card-body">
      <p class="text-muted small mb-3">
        These will also be installed on the same server. You can skip them and configure later.
      </p>
      <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" id="install_mailcow" name="install_mailcow"
               value="1" {{ old('install_mailcow') ? 'checked' : '' }}
               onchange="document.getElementById('mailcowConfig').classList.toggle('d-none', !this.checked)">
        <label class="form-check-label fw-semibold" for="install_mailcow">
          <i class="bi bi-envelope me-1 text-primary"></i>Install Mailcow
        </label>
      </div>
      <div id="mailcowConfig" class="{{ old('install_mailcow') ? '' : 'd-none' }} ms-4 mb-3">
        <label class="form-label">Mail server hostname (e.g. mail.company.com)</label>
        <input type="text" name="mailcow_hostname" class="form-control"
               value="{{ old('mailcow_hostname') }}"
               placeholder="mail.company.com" />
        <div class="form-text">Must resolve to this server's IP.</div>
      </div>

      <div class="form-check">
        <input class="form-check-input" type="checkbox" id="install_nextcloud" name="install_nextcloud"
               value="1" {{ old('install_nextcloud') ? 'checked' : '' }}>
        <label class="form-check-label fw-semibold" for="install_nextcloud">
          <i class="bi bi-cloud me-1 text-primary"></i>Install Nextcloud (AIO)
        </label>
      </div>
    </div>
  </div>

  {{-- ── Multi server ──────────────────────────────────────────────────── --}}
  @else

  {{-- Keycloak server --}}
  <div class="card shadow-sm mb-4">
    <div class="card-header">
      <h5 class="card-title mb-0"><i class="bi bi-shield-lock me-2"></i>Keycloak Server</h5>
    </div>
    <div class="card-body">
      <div class="alert alert-info small mb-4">
        <i class="bi bi-info-circle me-2"></i>
        SSH credentials are used only during setup and are <strong>never stored</strong>.
        Non-root usernames will use <code>sudo</code>.
      </div>
      <div class="row g-3 mx-0">
        <div class="col-sm-5">
          <label class="form-label">Server IP</label>
          <input type="text" name="kc_host" class="form-control"
                 value="{{ old('kc_host') }}" placeholder="10.0.0.10" required />
        </div>
        <div class="col-sm-3">
          <label class="form-label">SSH username</label>
          <input type="text" name="kc_user" class="form-control"
                 value="{{ old('kc_user', 'root') }}" required />
        </div>
        <div class="col-sm-4">
          <label class="form-label">SSH password</label>
          <input type="password" name="kc_pass" class="form-control" autocomplete="off" required />
        </div>
        <div class="col-sm-3">
          <label class="form-label">Keycloak port</label>
          <input type="number" name="kc_port" class="form-control"
                 value="{{ old('kc_port', 8080) }}" min="1" max="65535" required />
        </div>
      </div>
    </div>
  </div>

  {{-- Mailcow server --}}
  <div class="card shadow-sm mb-4">
    <div class="card-header d-flex align-items-center">
      <h5 class="card-title mb-0 me-auto"><i class="bi bi-envelope me-2"></i>Mailcow Server <small class="text-muted">(optional)</small></h5>
      <div class="form-check form-switch mb-0">
        <input class="form-check-input" type="checkbox" role="switch" id="mc_toggle" value="1" name="install_mailcow"
               {{ old('install_mailcow') ? 'checked' : '' }}
               onchange="document.getElementById('mcServer').classList.toggle('d-none', !this.checked)">
        <label class="form-check-label" for="mc_toggle">Install</label>
      </div>
    </div>
    <div id="mcServer" class="{{ old('install_mailcow') ? '' : 'd-none' }} card-body">
      <div class="row g-3 mx-0">
        <div class="col-sm-4">
          <label class="form-label small">Server IP</label>
          <input type="text" name="mc_host" class="form-control form-control-sm"
                 value="{{ old('mc_host') }}" placeholder="10.0.0.20" />
        </div>
        <div class="col-sm-3">
          <label class="form-label small">SSH username</label>
          <input type="text" name="mc_user" class="form-control form-control-sm"
                 value="{{ old('mc_user', 'root') }}" />
        </div>
        <div class="col-sm-5">
          <label class="form-label small">SSH password</label>
          <input type="password" name="mc_pass" class="form-control form-control-sm" autocomplete="off" />
        </div>
        <div class="col-sm-6">
          <label class="form-label small">Mail hostname (e.g. mail.company.com)</label>
          <input type="text" name="mailcow_hostname" class="form-control form-control-sm"
                 value="{{ old('mailcow_hostname') }}" placeholder="mail.company.com" />
        </div>
      </div>
    </div>
  </div>

  {{-- Nextcloud server --}}
  <div class="card shadow-sm mb-4">
    <div class="card-header d-flex align-items-center">
      <h5 class="card-title mb-0 me-auto"><i class="bi bi-cloud me-2"></i>Nextcloud Server <small class="text-muted">(optional)</small></h5>
      <div class="form-check form-switch mb-0">
        <input class="form-check-input" type="checkbox" role="switch" id="nc_toggle" value="1" name="install_nextcloud"
               {{ old('install_nextcloud') ? 'checked' : '' }}
               onchange="document.getElementById('ncServer').classList.toggle('d-none', !this.checked)">
        <label class="form-check-label" for="nc_toggle">Install</label>
      </div>
    </div>
    <div id="ncServer" class="{{ old('install_nextcloud') ? '' : 'd-none' }} card-body">
      <div class="row g-3 mx-0">
        <div class="col-sm-4">
          <label class="form-label small">Server IP</label>
          <input type="text" name="nc_host" class="form-control form-control-sm"
                 value="{{ old('nc_host') }}" placeholder="10.0.0.30" />
        </div>
        <div class="col-sm-3">
          <label class="form-label small">SSH username</label>
          <input type="text" name="nc_user" class="form-control form-control-sm"
                 value="{{ old('nc_user', 'root') }}" />
        </div>
        <div class="col-sm-5">
          <label class="form-label small">SSH password</label>
          <input type="password" name="nc_pass" class="form-control form-control-sm" autocomplete="off" />
        </div>
      </div>
    </div>
  </div>

  @endif

  <div class="d-flex justify-content-end mb-5">
    <button type="submit" class="btn btn-primary btn-lg">
      <i class="bi bi-play-fill me-1"></i>Start Installation
    </button>
  </div>
</form>

@push('scripts')
<script>
function toggleHostField(val) {
  const custom = document.getElementById('customHost');
  if (val === '__custom__') {
    custom.classList.remove('d-none');
    custom.name = 'ssh_host';
    document.querySelector('input[value="__local__"]').name = 'ssh_host_inactive';
  } else {
    custom.classList.add('d-none');
    custom.name = 'ssh_host_custom';
    document.querySelector('input[value="__local__"]').name = 'ssh_host';
  }
}
// Init
const selected = document.querySelector('input[name="ssh_host"]:checked');
if (selected) toggleHostField(selected.value);
</script>
@endpush
@endsection
