@extends('super.layout')

@section('page-title', 'Settings')

@section('content')
<div class="card" style="max-width:600px">
  <div class="card-header">
    <h3 class="card-title">Mailcow Settings</h3>
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

      <h6 class="text-muted mb-3">Connection</h6>
      <div class="mb-3">
        <label class="form-label">Mailcow URL</label>
        <input type="url" name="mailcow_url" class="form-control" value="{{ old('mailcow_url', $mailcow_url) }}" required />
        <small class="text-muted">Base URL of the default Mailcow instance. Changing this affects newly provisioned realms only.</small>
      </div>
      <div class="mb-4">
        <label class="form-label">API Key</label>
        <input type="text" name="mailcow_api_key" class="form-control" value="{{ old('mailcow_api_key', $mailcow_api_key) }}" placeholder="Leave blank to keep current" />
        <small class="text-muted">Stored encrypted. Leave blank to keep the current key.</small>
      </div>

      <hr />
      <h6 class="text-muted mb-3">Default Domain Limits</h6>
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
          <label class="form-label">Max quota per mailbox (MB)</label>
          <input type="number" name="mailcow_maxquota" class="form-control" value="{{ old('mailcow_maxquota', $mailcow_maxquota) }}" min="1" required />
          <small class="text-muted">{{ round($mailcow_maxquota / 1024, 1) }} GB</small>
        </div>
        <div class="col">
          <label class="form-label">Total domain quota (MB)</label>
          <input type="number" name="mailcow_quota" class="form-control" value="{{ old('mailcow_quota', $mailcow_quota) }}" min="1" required />
          <small class="text-muted">{{ round($mailcow_quota / 1024, 1) }} GB</small>
        </div>
      </div>

      <button type="submit" class="btn btn-primary">Save Settings</button>
    </form>
  </div>
</div>
@endsection

@push('scripts')
<script>
  ['mailcow_maxquota', 'mailcow_quota'].forEach(function (name) {
    const input = document.querySelector(`input[name="${name}"]`);
    const hint  = input.nextElementSibling;
    input.addEventListener('input', function () {
      hint.textContent = (Math.round(this.value / 1024 * 10) / 10) + ' GB';
    });
  });
</script>
@endpush
