@extends('super.layout')

@section('page-title', $realm)

@section('content')

<div class="mb-3">
  <a href="{{ route('super.realms') }}" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left me-1"></i>Back to Realms
  </a>
</div>

@if (session('success'))
  <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if ($errors->any())
  <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

<form method="POST" action="{{ route('super.realms.update', $realm) }}">
  @csrf
  @method('PUT')
  <input type="hidden" name="mailcow_exists"   value="{{ $mailcowExists   ? '1' : '0' }}">
  <input type="hidden" name="nextcloud_exists" value="{{ $nextcloudExists ? '1' : '0' }}">

  {{-- Keycloak / Limits --}}
  <div class="card mb-4">
    <div class="card-header">
      <h5 class="card-title mb-0"><i class="bi bi-people me-2"></i>Keycloak</h5>
    </div>
    <div class="card-body">
      <div class="row g-3 mx-0">
        <div class="col-sm-4">
          <label class="form-label">Max users</label>
          <input type="number" name="max_users" class="form-control"
                 min="1" placeholder="∞ Unlimited"
                 value="{{ old('max_users', $map->max_users) }}">
          <div class="form-text">Leave blank for unlimited.</div>
        </div>
      </div>
    </div>
  </div>

  {{-- Mailcow --}}
  @if($mailcowConfigured)
  <div class="card mb-4">
    <div class="card-header d-flex align-items-center">
      <h5 class="card-title mb-0 me-auto"><i class="bi bi-envelope me-2"></i>Mailcow</h5>
      <div class="form-check form-switch mb-0">
        <input class="form-check-input" type="checkbox" role="switch"
               name="mailcow_enabled" id="mailcow_enabled" value="1"
               {{ old('mailcow_enabled', $map->mailcow_enabled) ? 'checked' : '' }}
               {{ $mailcowExists ? 'data-provisioned' : '' }}>
        <label class="form-check-label" for="mailcow_enabled">Enabled</label>
        @if($mailcowExists)
          <small class="text-muted ms-2">— use <em>Remove Mailcow domain</em> below to disable</small>
        @endif
      </div>
    </div>
    <div id="mailcowSettings" class="card-body {{ old('mailcow_enabled', $map->mailcow_enabled) ? '' : 'd-none' }}">

      <h6 class="text-muted mb-3">Domain Limits</h6>
      <div class="row g-3 mx-0 mb-3">
        <div class="col-sm-3">
          <label class="form-label small">Mailboxes</label>
          <input type="number" name="mailcow_mailboxes" class="form-control form-control-sm"
                 min="1" value="{{ old('mailcow_mailboxes', $mailcowValues['mailboxes']) }}">
        </div>
        <div class="col-sm-3">
          <label class="form-label small">Distribution lists (aliases)</label>
          <input type="number" name="mailcow_aliases" class="form-control form-control-sm"
                 min="0" value="{{ old('mailcow_aliases', $mailcowValues['aliases']) }}">
        </div>
        <div class="col-sm-3">
          <label class="form-label small">Max quota per mailbox (GB)</label>
          <input type="number" name="mailcow_maxquota" class="form-control form-control-sm"
                 min="0.1" step="0.1" value="{{ old('mailcow_maxquota', $mailcowValues['maxquota']) }}">
        </div>
        <div class="col-sm-3">
          <label class="form-label small">Total domain quota (GB)</label>
          <input type="number" name="mailcow_quota" class="form-control form-control-sm"
                 min="0.1" step="0.1" value="{{ old('mailcow_quota', $mailcowValues['quota']) }}">
        </div>
      </div>

      <div class="row g-3 mx-0 mb-1">
        <div class="col-sm-3">
          <label class="form-label small">Max mailbox users (Lintune limit)</label>
          <input type="number" name="max_mailbox_users" class="form-control form-control-sm"
                 min="0" placeholder="∞ Unlimited"
                 value="{{ old('max_mailbox_users', $map->max_mailbox_users) }}">
          <div class="form-text">Controls how many mailboxes tenant admins can provision.</div>
        </div>
      </div>

      <div class="mt-4">
        <a href="#mailcowAdvanced" data-bs-toggle="collapse" class="text-muted small">
          <i class="bi bi-chevron-down me-1"></i>Advanced — Server Override
        </a>
        <div class="collapse mt-2" id="mailcowAdvanced">
          <div class="card card-body bg-light border-0 p-3">
            <p class="text-muted small mb-3">Leave blank to use the platform default Mailcow server.</p>
            <div class="row g-3 mx-0">
              <div class="col-sm-6">
                <label class="form-label small">Mailcow URL</label>
                <input type="url" name="mailcow_custom_url" class="form-control form-control-sm"
                       placeholder="https://mail.custom.com"
                       value="{{ old('mailcow_custom_url', $mailcowValues['custom_url']) }}">
              </div>
              <div class="col-sm-6">
                <label class="form-label small">API Key</label>
                <input type="text" name="mailcow_custom_api_key" class="form-control form-control-sm"
                       placeholder="Leave blank to keep current"
                       value="{{ old('mailcow_custom_api_key', $mailcowValues['custom_api_key']) }}">
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  @endif

  {{-- Nextcloud --}}
  @if($nextcloudConfigured)
  <div class="card mb-4">
    <div class="card-header d-flex align-items-center">
      <h5 class="card-title mb-0 me-auto"><i class="bi bi-cloud me-2"></i>Nextcloud</h5>
      <div class="form-check form-switch mb-0">
        <input class="form-check-input" type="checkbox" role="switch"
               name="nextcloud_enabled" id="nextcloud_enabled" value="1"
               {{ old('nextcloud_enabled', $map->nextcloud_enabled) ? 'checked' : '' }}
               {{ $nextcloudExists ? 'data-provisioned' : '' }}>
        <label class="form-check-label" for="nextcloud_enabled">Enabled</label>
        @if($nextcloudExists)
          <small class="text-muted ms-2">— use <em>Remove Nextcloud group</em> below to disable</small>
        @endif
      </div>
    </div>
    <div id="nextcloudSettings" class="card-body {{ old('nextcloud_enabled', $map->nextcloud_enabled) ? '' : 'd-none' }}">

      <div class="row g-3 mx-0 mb-1">
        <div class="col-sm-3">
          <label class="form-label small">Max Nextcloud users (Lintune limit)</label>
          <input type="number" name="max_nextcloud_users" class="form-control form-control-sm"
                 min="0" placeholder="∞ Unlimited"
                 value="{{ old('max_nextcloud_users', $map->max_nextcloud_users) }}">
          <div class="form-text">Controls how many Nextcloud accounts tenant admins can provision.</div>
        </div>
      </div>

      <div class="mt-4">
        <a href="#nextcloudAdvanced" data-bs-toggle="collapse" class="text-muted small">
          <i class="bi bi-chevron-down me-1"></i>Advanced — Server Override
        </a>
        <div class="collapse mt-2" id="nextcloudAdvanced">
          <div class="card card-body bg-light border-0 p-3">
            <p class="text-muted small mb-3">Leave blank to use the platform default Nextcloud server.</p>
            <div class="row g-3 mx-0">
              <div class="col-sm-4">
                <label class="form-label small">Nextcloud URL</label>
                <input type="url" name="nextcloud_custom_url" class="form-control form-control-sm"
                       placeholder="https://cloud.custom.com"
                       value="{{ old('nextcloud_custom_url', $nextcloudValues['custom_url']) }}">
              </div>
              <div class="col-sm-4">
                <label class="form-label small">Service account username</label>
                <input type="text" name="nextcloud_custom_service_user" class="form-control form-control-sm"
                       placeholder="Leave blank to use platform default"
                       value="{{ old('nextcloud_custom_service_user', $nextcloudValues['custom_service_user']) }}">
              </div>
              <div class="col-sm-4">
                <label class="form-label small">Service account app password</label>
                <input type="text" name="nextcloud_custom_service_password" class="form-control form-control-sm"
                       placeholder="Leave blank to keep current">
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  @endif

  <div class="d-flex justify-content-end gap-2 mb-5">
    <a href="{{ route('super.realms') }}" class="btn btn-secondary">Cancel</a>
    <button type="submit" class="btn btn-primary">
      <i class="bi bi-check-lg me-1"></i>Save Changes
    </button>
  </div>

</form>

{{-- Danger zone --}}
@php $canDelete = !$mailcowExists && !$nextcloudExists; @endphp
<div class="card border-danger mb-4">
  <div class="card-header text-danger">
    <h5 class="card-title mb-0"><i class="bi bi-exclamation-triangle me-2"></i>Danger Zone</h5>
  </div>
  <div class="card-body">

    @if($mailcowExists)
    <div class="d-flex align-items-center justify-content-between py-2 border-bottom mb-3">
      <div>
        <strong>Remove Mailcow domain</strong>
        <div class="text-muted small">Permanently deletes the domain and all its mailboxes from Mailcow. Cannot be undone.</div>
      </div>
      <form method="POST" action="{{ route('super.realms.remove-mailcow', $realm) }}"
            onsubmit="return confirm('Delete the Mailcow domain for {{ $realm }}? This will permanently remove all mailboxes. This cannot be undone.')">
        @csrf
        @method('DELETE')
        <button type="submit" class="btn btn-sm btn-outline-danger ms-4">
          <i class="bi bi-trash me-1"></i>Remove Mailcow domain
        </button>
      </form>
    </div>
    @endif

    @if($nextcloudExists)
    <div class="d-flex align-items-center justify-content-between py-2 border-bottom mb-3">
      <div>
        <strong>Remove Nextcloud group</strong>
        <div class="text-muted small">Permanently removes the Nextcloud admin group for this realm. Cannot be undone.</div>
      </div>
      <form method="POST" action="{{ route('super.realms.nextcloud-remove', $realm) }}"
            onsubmit="return confirm('Remove the Nextcloud group for {{ $realm }}? This cannot be undone.')">
        @csrf
        @method('DELETE')
        <button type="submit" class="btn btn-sm btn-outline-danger ms-4">
          <i class="bi bi-trash me-1"></i>Remove Nextcloud group
        </button>
      </form>
    </div>
    @endif

    <div class="d-flex align-items-start justify-content-between py-2">
      <div>
        <strong class="text-danger">Delete realm</strong>
        <div class="text-muted small">Permanently deletes the realm and all its users in Keycloak. This cannot be undone.</div>
        @if(!$canDelete)
          <div class="text-warning small mt-1">
            <i class="bi bi-lock me-1"></i>Remove
            @if($mailcowExists && $nextcloudExists)
              the Mailcow domain and Nextcloud group
            @elseif($mailcowExists)
              the Mailcow domain
            @else
              the Nextcloud group
            @endif
            above before deleting this realm.
          </div>
        @endif
      </div>
      <form method="POST" action="{{ route('super.realms.destroy', $realm) }}"
            onsubmit="return confirm('Permanently delete realm \"{{ $realm }}\" and ALL its users in Keycloak? This cannot be undone.')">
        @csrf
        @method('DELETE')
        <button type="submit" class="btn btn-sm btn-danger ms-4" {{ $canDelete ? '' : 'disabled' }}>
          <i class="bi bi-trash me-1"></i>Delete Realm
        </button>
      </form>
    </div>

  </div>
</div>

@push('scripts')
<script>
(function () {
  function bindToggle(checkboxId, targetId) {
    const cb  = document.getElementById(checkboxId);
    const box = document.getElementById(targetId);
    if (!cb || !box) return;
    cb.addEventListener('change', function () {
      if (!this.checked && this.dataset.provisioned !== undefined) {
        this.checked = true; // provisioned — must use Remove button below
        return;
      }
      box.classList.toggle('d-none', !this.checked);
    });
  }
  bindToggle('mailcow_enabled',   'mailcowSettings');
  bindToggle('nextcloud_enabled', 'nextcloudSettings');
})();
</script>
@endpush
@endsection
