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

<div class="alert alert-info small">
  <i class="bi bi-info-circle me-2"></i>
  SSH credentials are used <strong>only during this setup session</strong> and are never stored anywhere.
  If the username is not <code>root</code>, <code>sudo</code> will be used automatically.
</div>

<form method="POST" action="{{ route('install.run') }}" data-long-running>
  @csrf
  <input type="hidden" name="server_type" value="{{ $type }}">

  {{-- ── Keycloak section (always present) ──────────────────────────────── --}}
  <div class="card shadow-sm mb-4">
    <div class="card-header bg-primary-subtle">
      <h5 class="card-title mb-0">
        <i class="bi bi-shield-lock me-2 text-primary"></i>Keycloak
        <small class="text-muted fw-normal ms-2">— identity &amp; login provider (required)</small>
      </h5>
    </div>
    <div class="card-body">
      <p class="text-muted small mb-3">
        Keycloak will be installed on the server below via Docker.
        @if($type === 'single')
          Lintune will reach it at <code>http://&lt;host&gt;:&lt;port&gt;</code>.
        @else
          This can be a separate machine from Mailcow and Nextcloud.
        @endif
      </p>

      @if($type === 'single')
        <div class="mb-3">
          <label class="form-label fw-semibold">Install on</label>
          <div class="form-check mb-1">
            <input class="form-check-input" type="radio" name="ssh_host" id="ssh_localhost"
                   value="__local__" {{ old('ssh_host', '__local__') === '__local__' ? 'checked' : '' }}
                   onchange="toggleHostField(this.value)">
            <label class="form-check-label" for="ssh_localhost">
              <strong>This server</strong>
              <small class="text-muted">(the machine running Lintune admin)</small>
            </label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="radio" name="ssh_host" id="ssh_remote"
                   value="__custom__" {{ old('ssh_host') && old('ssh_host') !== '__local__' ? 'checked' : '' }}
                   onchange="toggleHostField(this.value)">
            <label class="form-check-label" for="ssh_remote">Different server (IP address)</label>
          </div>
          <input type="text" id="customHostInput" name="ssh_host_custom"
                 class="form-control mt-2 {{ old('ssh_host') && old('ssh_host') !== '__local__' ? '' : 'd-none' }}"
                 placeholder="10.0.0.10"
                 value="{{ old('ssh_host') !== '__local__' ? old('ssh_host') : '' }}">
        </div>
      @else
        <div class="mb-3">
          <label class="form-label fw-semibold">Keycloak server IP</label>
          <input type="text" name="kc_host" class="form-control"
                 value="{{ old('kc_host') }}" placeholder="10.0.0.10" required />
        </div>
      @endif

      <div class="row g-3 mx-0">
        @if($type === 'single')
          <div class="col-sm-5">
            <label class="form-label">SSH username</label>
            <input type="text" name="ssh_user" class="form-control"
                   value="{{ old('ssh_user', 'root') }}" required />
          </div>
          <div class="col-sm-5">
            <label class="form-label">SSH password</label>
            <input type="password" name="ssh_pass" class="form-control" autocomplete="off" required />
          </div>
        @else
          <div class="col-sm-5">
            <label class="form-label">SSH username</label>
            <input type="text" name="kc_user" class="form-control"
                   value="{{ old('kc_user', 'root') }}" required />
          </div>
          <div class="col-sm-5">
            <label class="form-label">SSH password</label>
            <input type="password" name="kc_pass" class="form-control" autocomplete="off" required />
          </div>
        @endif
        <div class="col-sm-2">
          <label class="form-label">Port</label>
          <input type="number" name="kc_port" class="form-control"
                 value="{{ old('kc_port', 8080) }}" min="1" max="65535" required />
        </div>
      </div>

      <div class="mt-3 p-2 bg-light rounded small text-muted">
        <i class="bi bi-info-circle me-1"></i>
        Docker will be installed on this server if not already present.
        Keycloak runs as a Docker container (start-dev mode — suitable behind a reverse proxy).
      </div>
    </div>
  </div>

  {{-- ── Mailcow section ─────────────────────────────────────────────────── --}}
  <div class="card shadow-sm mb-4">
    <div class="card-header d-flex align-items-center">
      <h5 class="card-title mb-0 me-auto">
        <i class="bi bi-envelope me-2 text-primary"></i>Mailcow
        <small class="text-muted fw-normal ms-2">— email hosting (optional)</small>
      </h5>
      <div class="form-check form-switch mb-0">
        <input class="form-check-input" type="checkbox" role="switch"
               id="mc_enabled" name="install_mailcow" value="1"
               {{ old('install_mailcow') ? 'checked' : '' }}
               onchange="document.getElementById('mcBody').classList.toggle('d-none', !this.checked)">
        <label class="form-check-label" for="mc_enabled">Install</label>
      </div>
    </div>
    <div id="mcBody" class="{{ old('install_mailcow') ? '' : 'd-none' }} card-body">
      <p class="text-muted small mb-3">
        Mailcow will be cloned from GitHub and started via Docker Compose.
        The mail hostname must have an MX record pointing to this server.
      </p>
      <div class="row g-3 mx-0">
        @if($type === 'multi')
          <div class="col-sm-4">
            <label class="form-label small">Mailcow server IP</label>
            <input type="text" name="mc_host" class="form-control form-control-sm"
                   value="{{ old('mc_host') }}" placeholder="10.0.0.20" />
          </div>
          <div class="col-sm-4">
            <label class="form-label small">SSH username</label>
            <input type="text" name="mc_user" class="form-control form-control-sm"
                   value="{{ old('mc_user', 'root') }}" />
          </div>
          <div class="col-sm-4">
            <label class="form-label small">SSH password</label>
            <input type="password" name="mc_pass" class="form-control form-control-sm" autocomplete="off" />
          </div>
        @endif
        <div class="col-sm-6">
          <label class="form-label small">Mail hostname</label>
          <input type="text" name="mailcow_hostname" class="form-control form-control-sm"
                 value="{{ old('mailcow_hostname') }}" placeholder="mail.company.com" />
          <div class="form-text">Must resolve to the Mailcow server's IP.</div>
        </div>
        <div class="col-sm-6">
          <label class="form-label small">Timezone</label>
          <input type="text" name="mailcow_tz" class="form-control form-control-sm"
                 value="{{ old('mailcow_tz', 'UTC') }}" placeholder="Europe/Amsterdam" />
        </div>
      </div>
    </div>
  </div>

  {{-- ── Nextcloud section ────────────────────────────────────────────────── --}}
  <div class="card shadow-sm mb-4">
    <div class="card-header d-flex align-items-center">
      <h5 class="card-title mb-0 me-auto">
        <i class="bi bi-cloud me-2 text-primary"></i>Nextcloud
        <small class="text-muted fw-normal ms-2">— file storage (optional)</small>
      </h5>
      <div class="form-check form-switch mb-0">
        <input class="form-check-input" type="checkbox" role="switch"
               id="nc_enabled" name="install_nextcloud" value="1"
               {{ old('install_nextcloud') ? 'checked' : '' }}
               onchange="document.getElementById('ncBody').classList.toggle('d-none', !this.checked)">
        <label class="form-check-label" for="nc_enabled">Install</label>
      </div>
    </div>
    <div id="ncBody" class="{{ old('install_nextcloud') ? '' : 'd-none' }} card-body">
      <p class="text-muted small mb-3">
        Installs Nextcloud All-in-One via Docker. The AIO admin interface will be
        available on port 8080 after installation.
      </p>
      @if($type === 'multi')
      <div class="row g-3 mx-0">
        <div class="col-sm-4">
          <label class="form-label small">Nextcloud server IP</label>
          <input type="text" name="nc_host" class="form-control form-control-sm"
                 value="{{ old('nc_host') }}" placeholder="10.0.0.30" />
        </div>
        <div class="col-sm-4">
          <label class="form-label small">SSH username</label>
          <input type="text" name="nc_user" class="form-control form-control-sm"
                 value="{{ old('nc_user', 'root') }}" />
        </div>
        <div class="col-sm-4">
          <label class="form-label small">SSH password</label>
          <input type="password" name="nc_pass" class="form-control form-control-sm" autocomplete="off" />
        </div>
      </div>
      @endif
    </div>
  </div>

  <div class="d-flex justify-content-end mb-5">
    <button type="submit" class="btn btn-primary btn-lg">
      <i class="bi bi-play-fill me-1"></i>Start Installation
    </button>
  </div>
</form>

@push('scripts')
<script>
function toggleHostField(val) {
  const inp  = document.getElementById('customHostInput');
  const self = document.getElementById('ssh_localhost');
  if (val === '__custom__') {
    inp.classList.remove('d-none');
    inp.name  = 'ssh_host';
    self.name = 'ssh_host_inactive';
  } else {
    inp.classList.add('d-none');
    inp.name  = 'ssh_host_custom';
    self.name = 'ssh_host';
  }
}
const sel = document.querySelector('input[name="ssh_host"]:checked');
if (sel) toggleHostField(sel.value);
</script>
@endpush
@endsection
