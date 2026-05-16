@extends('super.layout')

@section('page-title', 'Vaultwarden')

@section('content')

<div class="card" style="max-width:640px">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h3 class="card-title mb-0">Vaultwarden Integration</h3>
    @if ($reachable)
      <span class="badge text-bg-success">
        <i class="bi bi-circle-fill me-1" style="font-size:.6rem"></i>Reachable
      </span>
    @else
      <span class="badge text-bg-secondary">
        <i class="bi bi-circle-fill me-1" style="font-size:.6rem"></i>Unreachable
      </span>
    @endif
  </div>
  <div class="card-body">

    @if (session('success'))
      <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
      <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    {{-- Connection --}}
    <h6 class="text-muted mb-3">Connection</h6>
    <div class="mb-4">
      <label class="form-label">Public URL</label>
      <div class="input-group">
        <input type="text" class="form-control" value="{{ $vwUrl }}" readonly />
        <a href="{{ $vwUrl }}" target="_blank" class="btn btn-outline-secondary">
          <i class="bi bi-box-arrow-up-right"></i>
        </a>
      </div>
      <small class="text-muted">Deployed on this server alongside lintune-admin.</small>
    </div>

    <hr />

    {{-- Keycloak SSO --}}
    <h6 class="text-muted mb-3">Keycloak SSO</h6>

    @if ($ssoWired)
      <div class="alert alert-success py-2 mb-3">
        <i class="bi bi-check-circle-fill me-2"></i>
        SSO active — broker realm client <code>{{ $kcClient }}</code>
        @if ($authority)
          <div class="small text-muted mt-1">Authority: {{ $authority }}</div>
        @endif
      </div>
    @else
      <p class="text-muted small mb-3">
        Wiring SSO creates an OIDC client in the Keycloak broker realm and pushes the
        authority/client credentials to Vaultwarden's admin API. Users log in via their
        realm email — broker home IdP discovery routes them automatically.
      </p>
    @endif

    <form method="POST" action="{{ route('super.vaultwarden.wire') }}">
      @csrf
      <div class="mb-3">
        <label class="form-label">
          {{ $ssoWired ? 'Admin Token (re-wire)' : 'Admin Token' }}
        </label>
        <input type="password" name="admin_token" class="form-control"
               placeholder="{{ $ssoWired ? 'Enter token to re-wire SSO' : 'Plain-text token from /opt/lintune/.env (VW_ADMIN_TOKEN)' }}"
               autocomplete="new-password" {{ $ssoWired ? '' : 'required' }} />
        <small class="text-muted">Stored encrypted. Used once to push config via the admin API.</small>
      </div>
      <button type="submit" class="btn {{ $ssoWired ? 'btn-outline-secondary' : 'btn-primary' }}">
        <i class="bi bi-link-45deg me-1"></i>
        {{ $ssoWired ? 'Re-wire SSO' : 'Wire Keycloak SSO' }}
      </button>
    </form>

    <hr class="mt-4" />

    {{-- Domain whitelist --}}
    <h6 class="text-muted mb-2">Signup Domain Whitelist</h6>
    <p class="text-muted small mb-0">
      Managed automatically — when a realm is created its domain is added to the Vaultwarden
      signup whitelist, and removed when the realm is deleted.
      Only users whose email domain matches a whitelisted realm can register via SSO.
    </p>

  </div>
</div>

@endsection
