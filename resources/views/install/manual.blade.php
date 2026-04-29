@extends('install.layout')

@php
  $steps = [
    ['label' => 'Welcome',   'state' => 'done'],
    ['label' => 'Configure', 'state' => 'active'],
    ['label' => 'Done',      'state' => 'pending'],
  ];
  $stepLabel = 'Manual setup';
@endphp

@section('content')
<div class="mb-3">
  <a href="{{ route('install.welcome') }}" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left me-1"></i>Back
  </a>
</div>

<div class="card shadow-sm">
  <div class="card-header">
    <h5 class="card-title mb-0"><i class="bi bi-shield-lock me-2"></i>Connect to Existing Keycloak</h5>
  </div>
  <div class="card-body">

    @if ($errors->any())
      <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <p class="text-muted small mb-4">
      Enter your Keycloak base URL and a master realm admin account.
      Lintune will create the required OIDC client and service account automatically.
      You can configure Mailcow and Nextcloud later in <strong>Settings</strong>.
    </p>

    <form method="POST" action="{{ route('install.manual.run') }}" data-long-running>
      @csrf

      <div class="mb-3">
        <label class="form-label fw-semibold">Keycloak URL</label>
        <input type="url" name="keycloak_url" class="form-control"
               value="{{ old('keycloak_url', $keycloakUrl) }}"
               placeholder="https://auth.company.com"
               required />
        <div class="form-text">Base URL of your Keycloak instance (no trailing slash).</div>
      </div>

      <div class="mb-3">
        <label class="form-label">Master realm admin username</label>
        <input type="text" name="username" class="form-control"
               value="{{ old('username') }}" required autocomplete="username" />
      </div>
      <div class="mb-4">
        <label class="form-label">Master realm admin password</label>
        <input type="password" name="password" class="form-control"
               required autocomplete="current-password" />
        <div class="form-text">Used only to configure Lintune — not stored.</div>
      </div>

      <button type="submit" class="btn btn-primary w-100">
        <i class="bi bi-check-lg me-1"></i>Connect &amp; Configure
      </button>
    </form>
  </div>
</div>
@endsection
