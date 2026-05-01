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

@if($baseDomain)
<div class="alert alert-success small mb-4">
  <div class="fw-semibold mb-2"><i class="bi bi-globe me-2"></i>Base domain: <code>{{ $baseDomain }}</code> — service subdomains are pre-configured below.</div>
  <div class="row row-cols-2 row-cols-md-4 g-1 mt-1">
    @foreach(['auth' => 'Keycloak', 'mail' => 'Mailcow', 'cloud' => 'Nextcloud', 'vault' => 'Vaultwarden'] as $prefix => $label)
      <div class="col"><code>{{ $prefix }}.{{ $baseDomain }}</code> <span class="text-muted">→ {{ $label }}</span></div>
    @endforeach
  </div>
</div>
@endif

<form method="POST" action="{{ route('install.run') }}">
  @csrf
  <input type="hidden" name="server_type" value="{{ $type }}">

  {{-- ── General settings ────────────────────────────────────────────────── --}}
  <div class="card shadow-sm mb-4">
    <div class="card-header">
      <h5 class="card-title mb-0">
        <i class="bi bi-gear me-2 text-primary"></i>General settings
      </h5>
    </div>
    <div class="card-body">
      <div class="row g-3 mx-0">
        <div class="col-sm-4">
          <label class="form-label fw-semibold">Timezone</label>
          <input type="text" name="timezone" class="form-control @error('timezone') is-invalid @enderror"
                 value="{{ old('timezone', 'UTC') }}"
                 placeholder="Europe/Amsterdam" required />
          @error('timezone')
            <div class="invalid-feedback">{{ $message }}</div>
          @enderror
          <div class="form-text">Used for Mailcow, Nextcloud, and other services. Use <a href="https://en.wikipedia.org/wiki/List_of_tz_database_time_zones" target="_blank">tz database name</a> (e.g. <code>Asia/Manila</code>, <code>America/New_York</code>).</div>
        </div>
      </div>
    </div>
  </div>

  {{-- ── Keycloak section ────────────────────────────────────────────────── --}}
  <div class="card shadow-sm mb-4">
    <div class="card-header bg-primary-subtle">
      <h5 class="card-title mb-0">
        <i class="bi bi-shield-lock me-2 text-primary"></i>Keycloak
        <small class="text-muted fw-normal ms-2">— identity &amp; login provider (required)</small>
      </h5>
    </div>
    <div class="card-body">
      <p class="text-muted small mb-3">
        Keycloak will be installed via Docker on the server below.
        @if($type === 'single')
          It will be reachable at <code>http://&lt;host&gt;:&lt;port&gt;</code> unless you provide a public URL.
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

      <div class="row g-3 mx-0 mb-3">
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

      <div class="mb-0">
        <label class="form-label">Public URL <span class="text-muted fw-normal">(optional — if behind a reverse proxy)</span></label>
        <input type="url" name="kc_public_url" class="form-control"
               value="{{ old('kc_public_url', $baseDomain ? 'https://auth.'.$baseDomain : '') }}"
               placeholder="https://auth.company.com" />
        <div class="form-text">
          If provided, Keycloak is configured with reverse-proxy headers and <code>KC_HOSTNAME</code> is set to this domain.
          Leave blank for direct <code>http://ip:port</code> access.
        </div>
      </div>

      <div class="mt-3 p-2 bg-light rounded small text-muted">
        <i class="bi bi-info-circle me-1"></i>
        Docker will be installed automatically if not already present.
        Keycloak runs as a Docker container (start-dev mode when no public URL is given — suitable for testing or behind a reverse proxy you configure yourself).
      </div>

      <hr class="my-4">

      <h6 class="fw-semibold mb-1"><i class="bi bi-person-lock me-2 text-primary"></i>Master realm admin account</h6>
      <p class="text-muted small mb-3">
        This account will be created in Keycloak's master realm. You use it to log in to the Keycloak admin UI.
        Choose a username (no spaces or @ sign) and a strong password — you'll need to remember these.
      </p>

      <div class="row g-3 mx-0">
        <div class="col-sm-4">
          <label class="form-label fw-semibold">Username</label>
          <input type="text" name="admin_username" class="form-control @error('admin_username') is-invalid @enderror"
                 value="{{ old('admin_username') }}"
                 placeholder="operator"
                 autocomplete="off"
                 pattern="[a-zA-Z0-9_\-]+" title="Letters, numbers, hyphens and underscores only"
                 required />
          @error('admin_username')
            <div class="invalid-feedback">{{ $message }}</div>
          @enderror
          <div class="form-text">Letters, numbers, <code>-</code> and <code>_</code> only.</div>
        </div>
        <div class="col-sm-4">
          <label class="form-label fw-semibold">Password</label>
          <input type="password" id="adminPassword" name="admin_password"
                 class="form-control @error('admin_password') is-invalid @enderror"
                 autocomplete="new-password" required />
          @error('admin_password')
            <div class="invalid-feedback">{{ $message }}</div>
          @enderror
        </div>
        <div class="col-sm-4">
          <label class="form-label fw-semibold">Confirm password</label>
          <input type="password" id="adminPasswordConfirm" name="admin_password_confirmation"
                 class="form-control" autocomplete="new-password" required />
        </div>
      </div>

      <div class="mt-2 ps-1" id="pwReqs">
        <small class="text-muted me-3"><span id="req-len">○</span> At least 10 characters</small>
        <small class="text-muted me-3"><span id="req-upper">○</span> Uppercase letter</small>
        <small class="text-muted me-3"><span id="req-lower">○</span> Lowercase letter</small>
        <small class="text-muted me-3"><span id="req-digit">○</span> Number</small>
        <small class="text-muted"><span id="req-special">○</span> Special character</small>
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
                 value="{{ old('mailcow_hostname', $baseDomain ? 'mail.'.$baseDomain : '') }}" placeholder="mail.company.com" />
          <div class="form-text">Must resolve to the Mailcow server's IP.</div>
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
               onchange="toggleNcSection(this.checked)">
        <label class="form-check-label" for="nc_enabled">Install</label>
      </div>
    </div>
    <div id="ncBody" class="{{ old('install_nextcloud') ? '' : 'd-none' }} card-body">
      <p class="text-muted small mb-3">
        Installs Nextcloud All-in-One via Docker and automatically configures the domain and timezone.
        Nextcloud's web interface runs on the domain you provide once AIO setup completes.
      </p>
      <div class="row g-3 mx-0">
        @if($type === 'multi')
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
        @endif
        <div class="col-12">
          <label class="form-label small fw-semibold">Nextcloud public URL <span class="text-danger">*</span></label>
          <input type="url" id="nc_url" name="nc_url" class="form-control form-control-sm @error('nc_url') is-invalid @enderror"
                 value="{{ old('nc_url', $baseDomain ? 'https://cloud.'.$baseDomain : '') }}" placeholder="https://cloud.company.com" />
          @error('nc_url')
            <div class="invalid-feedback">{{ $message }}</div>
          @enderror
          <div class="form-text">Required — used to configure Nextcloud AIO. The domain must point to this server.</div>
        </div>
      </div>
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
(function () {
  const pw      = document.getElementById('adminPassword');
  const confirm = document.getElementById('adminPasswordConfirm');
  const checks  = {
    'req-len':     v => v.length >= 10,
    'req-upper':   v => /[A-Z]/.test(v),
    'req-lower':   v => /[a-z]/.test(v),
    'req-digit':   v => /\d/.test(v),
    'req-special': v => /[^a-zA-Z0-9]/.test(v),
  };
  pw.addEventListener('input', function () {
    const v = this.value;
    for (const [id, fn] of Object.entries(checks)) {
      const el = document.getElementById(id);
      const ok = fn(v);
      el.textContent = ok ? '●' : '○';
      el.parentElement.classList.toggle('text-success', ok);
      el.parentElement.classList.toggle('text-muted',   !ok);
    }
    if (confirm.value) {
      confirm.setCustomValidity(confirm.value === v ? '' : 'Passwords do not match.');
    }
  });
  confirm.addEventListener('input', function () {
    this.setCustomValidity(this.value === pw.value ? '' : 'Passwords do not match.');
  });
})();

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

function toggleNcSection(enabled) {
  document.getElementById('ncBody').classList.toggle('d-none', !enabled);
  const urlField = document.getElementById('nc_url');
  if (urlField) urlField.required = enabled;
}
// Sync required state on page load (e.g. after validation error repopulates the form)
toggleNcSection(document.getElementById('nc_enabled').checked);
</script>
@endpush
@endsection
