@extends('super.layout')

@section('page-title', 'Settings')

@section('content')
<div class="card" style="max-width:600px">
  <div class="card-header">
    <h3 class="card-title">Platform Settings</h3>
  </div>
  <div class="card-body">
    @if (session('success'))
      <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
      <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('super.settings.update') }}">
      @csrf
      @method('PUT')

      {{-- Mailcow --}}
      <h6 class="text-muted mb-3">Mailcow — Connection</h6>
      <div class="mb-3">
        <label class="form-label">URL</label>
        <input type="url" name="mailcow_url" class="form-control" value="{{ old('mailcow_url', $mailcow_url) }}" required />
        <small class="text-muted">Changing this affects newly provisioned realms only.</small>
      </div>
      <div class="mb-4">
        <label class="form-label">API Key</label>
        <input type="text" name="mailcow_api_key" class="form-control" value="{{ old('mailcow_api_key', $mailcow_api_key) }}" placeholder="Leave blank to keep current" />
        <small class="text-muted">Stored encrypted.</small>
      </div>

      <h6 class="text-muted mb-2">Mailcow — Default Domain Limits</h6>
      <p class="text-muted small mb-3">Applied when provisioning a new Mailcow domain. Can be overridden per realm.</p>
      <div class="row mb-3">
        <div class="col">
          <label class="form-label">Mailboxes</label>
          <input type="number" name="mailcow_mailboxes" class="form-control" value="{{ old('mailcow_mailboxes', $mailcow_mailboxes) }}" min="1" required />
        </div>
        <div class="col">
          <label class="form-label">Aliases</label>
          <input type="number" name="mailcow_aliases" class="form-control" value="{{ old('mailcow_aliases', $mailcow_aliases) }}" min="0" required />
        </div>
      </div>
      <div class="row mb-4">
        <div class="col">
          <label class="form-label">Max quota per mailbox (GB)</label>
          <input type="number" name="mailcow_maxquota" class="form-control" value="{{ old('mailcow_maxquota', $mailcow_maxquota) }}" min="0.1" step="0.1" required />
        </div>
        <div class="col">
          <label class="form-label">Total domain quota (GB)</label>
          <input type="number" name="mailcow_quota" class="form-control" value="{{ old('mailcow_quota', $mailcow_quota) }}" min="0.1" step="0.1" required />
        </div>
      </div>

      <hr />

      {{-- Nextcloud --}}
      <h6 class="text-muted mb-3">Nextcloud — Connection</h6>
      <div class="mb-3">
        <label class="form-label">URL</label>
        <input type="url" name="nextcloud_url" class="form-control" value="{{ old('nextcloud_url', $nextcloud_url) }}" placeholder="https://cloud.yourdomain.com" />
      </div>
      <div class="mb-3">
        <label class="form-label">Service account username</label>
        <input type="text" name="nextcloud_user" class="form-control" value="{{ old('nextcloud_user', $nextcloud_user ?: 'lintune-service') }}" />
      </div>
      <div class="mb-3">
        <label class="form-label">Service account app password</label>
        <input type="text" name="nextcloud_password" class="form-control" value="{{ old('nextcloud_password', $nextcloud_password) }}" placeholder="Leave blank to keep current" />
        <small class="text-muted">Stored encrypted. Generate in Nextcloud → Settings → Security → App passwords.</small>
      </div>
      <div class="mb-4">
        <label class="form-label">Default quota per user (GB)</label>
        <input type="number" name="nextcloud_quota" class="form-control" value="{{ old('nextcloud_quota', $nextcloud_quota) }}" min="0.1" step="0.1" required />
      </div>

      <button type="submit" class="btn btn-primary">Save Settings</button>
    </form>
  </div>
</div>
@endsection
