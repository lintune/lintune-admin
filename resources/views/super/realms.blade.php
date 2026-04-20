@extends('super.layout')

@section('page-title', 'Realms')

@section('content')
<div class="card">
  <div class="card-header d-flex align-items-center">
    <h3 class="card-title me-auto">Realm List</h3>
    <a href="{{ route('super.realms.create') }}" class="btn btn-primary btn-sm">
      <i class="bi bi-plus-lg me-1"></i>New Realm
    </a>
  </div>
  <div class="card-body p-0">
    @if (session('success'))
      <div class="alert alert-success m-3">{{ session('success') }}</div>
    @endif
    @if (session('warning'))
      <div class="alert alert-warning m-3">{{ session('warning') }}</div>
    @endif
    @if ($errors->any())
      <div class="alert alert-danger m-3">{{ $errors->first() }}</div>
    @endif

    @if (empty($realms))
      <p class="text-center p-4 text-muted">No realms found.</p>
    @else
      <table class="table table-striped table-hover mb-0 align-middle">
        <thead>
          <tr>
            <th>Realm</th>
            <th>Display Name</th>
            <th>Status</th>
            @if($mailcowConfigured)<th>Mailcow</th>@endif
            <th></th>
          </tr>
        </thead>
        <tbody>
          @foreach ($realms as $realm)
          @php
            $enabled        = $realm['enabled'] ?? false;
            $mailcowEnabled = $domainMaps[$realm['realm']] ?? false;
          @endphp
          <tr>
            <td>{{ $realm['realm'] }}</td>
            <td>{{ $realm['displayName'] ?? '–' }}</td>
            <td>
              <span class="badge text-bg-{{ $enabled ? 'success' : 'secondary' }}">
                {{ $enabled ? 'Enabled' : 'Disabled' }}
              </span>
            </td>
            @if($mailcowConfigured)
            <td>
              <button type="button"
                class="btn btn-sm {{ $mailcowEnabled ? 'btn-success' : 'btn-secondary' }}"
                data-realm="{{ $realm['realm'] }}"
                data-bs-toggle="modal" data-bs-target="#mailcowModal">
                <i class="bi bi-envelope me-1"></i>Mailcow
              </button>
            </td>
            @endif
            <td class="text-end pe-3">
              <button type="button" class="btn btn-sm {{ $enabled ? 'btn-warning' : 'btn-success' }} me-1"
                data-action="toggle"
                data-realm="{{ $realm['realm'] }}"
                data-enabled="{{ $enabled ? '1' : '0' }}"
                data-bs-toggle="modal" data-bs-target="#confirmModal">
                <i class="bi bi-{{ $enabled ? 'pause-circle' : 'play-circle' }} me-1"></i>{{ $enabled ? 'Disable' : 'Enable' }}
              </button>
              <button type="button" class="btn btn-sm btn-danger"
                data-action="delete"
                data-realm="{{ $realm['realm'] }}"
                data-mailcow-enabled="{{ $mailcowEnabled ? '1' : '0' }}"
                data-bs-toggle="modal" data-bs-target="#confirmModal">
                <i class="bi bi-trash me-1"></i>Delete
              </button>
            </td>
          </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>
</div>

{{-- Mailcow modal --}}
<div class="modal fade" id="mailcowModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Mailcow</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="mailcowLoading" class="text-center py-4">
          <div class="spinner-border spinner-border-sm me-2"></div>Loading...
        </div>
        <div id="mailcowContent" style="display:none">
          <form id="mailcowForm" method="POST">
            @csrf
            @method('PUT')
            <input type="hidden" name="exists" id="mailcowExists" value="0">

            <div class="form-check form-switch mb-4">
              <input class="form-check-input" type="checkbox" name="enabled" id="mailcowEnabled" value="1" role="switch">
              <label class="form-check-label" for="mailcowEnabled">Mailcow enabled for this realm</label>
            </div>

            <div id="mailcowLimits">
              <h6 class="text-muted mb-3">Domain Limits</h6>
              <div class="row g-3 mb-3">
                <div class="col-6">
                  <label class="form-label small">Mailboxes</label>
                  <input type="number" name="mailboxes" id="mailcowMailboxes" class="form-control form-control-sm" min="1" required>
                </div>
                <div class="col-6">
                  <label class="form-label small">Aliases</label>
                  <input type="number" name="aliases" id="mailcowAliases" class="form-control form-control-sm" min="0" required>
                </div>
                <div class="col-6">
                  <label class="form-label small">Max quota per mailbox (MB)</label>
                  <input type="number" name="maxquota" id="mailcowMaxquota" class="form-control form-control-sm" min="1" required>
                  <small class="text-muted" id="maxquotaHint"></small>
                </div>
                <div class="col-6">
                  <label class="form-label small">Total domain quota (MB)</label>
                  <input type="number" name="quota" id="mailcowQuota" class="form-control form-control-sm" min="1" required>
                  <small class="text-muted" id="quotaHint"></small>
                </div>
              </div>
              <div id="mailcowLowerWarning" class="alert alert-warning d-none">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                One or more limits are being lowered. This may affect existing mailboxes that exceed the new limits.
              </div>
            </div>
          </form>

          <hr>
          <div id="mailcowRemoveSection">
            <p class="text-muted small mb-2">Permanently remove this domain and all its mailboxes from Mailcow. This cannot be undone.</p>
            <form id="mailcowRemoveForm" method="POST">
              @csrf
              @method('DELETE')
              <button type="button" class="btn btn-sm btn-outline-danger" id="mailcowRemoveBtn">
                <i class="bi bi-trash me-1"></i>Remove domain from Mailcow
              </button>
            </form>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" form="mailcowForm" class="btn btn-primary" id="mailcowSaveBtn" style="display:none">Save</button>
      </div>
    </div>
  </div>
</div>

{{-- Confirm modal --}}
<div class="modal fade" id="confirmModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body"></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <form id="confirmForm" method="POST">
          @csrf
          <input type="hidden" name="_method" id="confirmMethod" value="POST">
          <button type="submit" class="btn" id="confirmBtn">Confirm</button>
        </form>
      </div>
    </div>
  </div>
</div>

@push('scripts')
<script>
// Mailcow modal
let mailcowOriginal = {};

document.getElementById('mailcowModal').addEventListener('show.bs.modal', function (e) {
  const realm = e.relatedTarget.dataset.realm;

  document.getElementById('mailcowLoading').style.display = '';
  document.getElementById('mailcowContent').style.display = 'none';
  document.getElementById('mailcowSaveBtn').style.display = 'none';
  document.getElementById('mailcowLowerWarning').classList.add('d-none');

  document.getElementById('mailcowForm').action = `/super/realms/${realm}/mailcow-limits`;
  document.getElementById('mailcowRemoveForm').action = `/super/realms/${realm}/mailcow`;

  fetch(`/super/realms/${realm}/mailcow-settings`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => r.json())
    .then(data => {
      mailcowOriginal = { mailboxes: data.mailboxes, aliases: data.aliases, maxquota: data.maxquota, quota: data.quota };

      document.getElementById('mailcowExists').value      = data.exists ? '1' : '0';
      document.getElementById('mailcowEnabled').checked   = data.mailcow_enabled;
      document.getElementById('mailcowMailboxes').value   = data.mailboxes;
      document.getElementById('mailcowAliases').value     = data.aliases;
      document.getElementById('mailcowMaxquota').value    = data.maxquota;
      document.getElementById('mailcowQuota').value       = data.quota;
      document.getElementById('maxquotaHint').textContent = (Math.round(data.maxquota / 1024 * 10) / 10) + ' GB';
      document.getElementById('quotaHint').textContent    = (Math.round(data.quota / 1024 * 10) / 10) + ' GB';

      // Only show remove section if domain exists in Mailcow
      document.getElementById('mailcowRemoveSection').style.display = data.exists ? '' : 'none';

      document.getElementById('mailcowLoading').style.display  = 'none';
      document.getElementById('mailcowContent').style.display  = '';
      document.getElementById('mailcowSaveBtn').style.display  = '';
    })
    .catch(() => {
      document.getElementById('mailcowLoading').innerHTML = '<div class="alert alert-danger mb-0">Could not load Mailcow settings.</div>';
    });
});

// Live GB hints + lower warning
['mailcowMaxquota', 'mailcowQuota'].forEach(function (id) {
  const input = document.getElementById(id);
  const hint  = document.getElementById(id === 'mailcowMaxquota' ? 'maxquotaHint' : 'quotaHint');
  input.addEventListener('input', function () {
    hint.textContent = (Math.round(this.value / 1024 * 10) / 10) + ' GB';
    checkLowerWarning();
  });
});

['mailcowMailboxes', 'mailcowAliases'].forEach(function (id) {
  document.getElementById(id).addEventListener('input', checkLowerWarning);
});

function checkLowerWarning() {
  const lower =
    parseInt(document.getElementById('mailcowMailboxes').value) < mailcowOriginal.mailboxes ||
    parseInt(document.getElementById('mailcowAliases').value)   < mailcowOriginal.aliases   ||
    parseInt(document.getElementById('mailcowMaxquota').value)  < mailcowOriginal.maxquota  ||
    parseInt(document.getElementById('mailcowQuota').value)     < mailcowOriginal.quota;
  document.getElementById('mailcowLowerWarning').classList.toggle('d-none', !lower);
}

// Remove button — confirm before submitting
document.getElementById('mailcowRemoveBtn').addEventListener('click', function () {
  if (confirm('This will permanently delete the domain and all its mailboxes from Mailcow. This cannot be undone. Are you sure?')) {
    document.getElementById('mailcowRemoveForm').submit();
  }
});

// Confirm modal (toggle realm / delete realm)
document.getElementById('confirmModal').addEventListener('show.bs.modal', function (e) {
  const btn     = e.relatedTarget;
  const action  = btn.dataset.action;
  const realm   = btn.dataset.realm;
  const enabled = btn.dataset.enabled === '1';

  const form    = document.getElementById('confirmForm');
  const method  = document.getElementById('confirmMethod');
  const title   = this.querySelector('.modal-title');
  const body    = this.querySelector('.modal-body');
  const confirm = document.getElementById('confirmBtn');

  form.onsubmit = null;

  if (action === 'delete') {
    const mailcowEnabled = btn.dataset.mailcowEnabled === '1';
    form.action   = `/super/realms/${realm}`;
    method.value  = 'DELETE';
    title.textContent = `Delete realm "${realm}"`;
    body.innerHTML = `
      <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i>
        This will permanently delete the realm and all its users in Keycloak. This cannot be undone.</div>
      ${mailcowEnabled ? `
      <div class="form-check">
        <input class="form-check-input" type="checkbox" id="deleteMailcowCheck">
        <label class="form-check-label" for="deleteMailcowCheck">Also delete the Mailcow domain and all its mailboxes</label>
      </div>` : ''}`;
    confirm.className   = 'btn btn-danger';
    confirm.textContent = 'Yes, delete';
    form.onsubmit = function () {
      document.getElementById('deleteMailcowField')?.remove();
      if (mailcowEnabled && document.getElementById('deleteMailcowCheck')?.checked) {
        const h = document.createElement('input');
        h.type = 'hidden'; h.name = 'delete_mailcow'; h.value = '1'; h.id = 'deleteMailcowField';
        form.appendChild(h);
      }
    };
  } else {
    form.action   = `/super/realms/${realm}/toggle`;
    method.value  = 'POST';
    const verb    = enabled ? 'disable' : 'enable';
    title.textContent = `${verb.charAt(0).toUpperCase() + verb.slice(1)} realm "${realm}"`;
    body.innerHTML = enabled
      ? `<div class="alert alert-warning mb-0"><i class="bi bi-exclamation-triangle-fill me-2"></i>Disabling this realm will prevent all users from logging in.</div>`
      : `<p class="mb-0">Users will be able to log in again once the realm is enabled.</p>`;
    confirm.className   = enabled ? 'btn btn-warning' : 'btn btn-success';
    confirm.textContent = `Yes, ${verb}`;
  }
});
</script>
@endpush
@endsection
