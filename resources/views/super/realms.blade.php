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
            @if(config('mailcow.url'))<th>Mailcow</th>@endif
            <th></th>
          </tr>
        </thead>
        <tbody>
          @foreach ($realms as $realm)
          @php
            $enabled = $realm['enabled'] ?? false;
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
            @if(config('mailcow.url'))
            <td>
              <span class="badge text-bg-{{ $mailcowEnabled ? 'success' : 'secondary' }}">
                <i class="bi bi-envelope{{ $mailcowEnabled ? '-check' : '' }} me-1"></i>{{ $mailcowEnabled ? 'Active' : 'None' }}
              </span>
            </td>
            @endif
            <td class="text-end pe-3">
              @if($realm['realm'] !== 'master')
              <button type="button" class="btn btn-sm {{ $enabled ? 'btn-warning' : 'btn-success' }} me-1"
                data-action="toggle"
                data-realm="{{ $realm['realm'] }}"
                data-enabled="{{ $enabled ? '1' : '0' }}"
                data-bs-toggle="modal" data-bs-target="#confirmModal">
                <i class="bi bi-{{ $enabled ? 'pause-circle' : 'play-circle' }} me-1"></i>{{ $enabled ? 'Disable' : 'Enable' }}
              </button>
              @if(config('mailcow.url'))
              <button type="button" class="btn btn-sm {{ $mailcowEnabled ? 'btn-outline-danger' : 'btn-outline-primary' }} me-1"
                data-action="toggle-mailcow"
                data-realm="{{ $realm['realm'] }}"
                data-mailcow-enabled="{{ $mailcowEnabled ? '1' : '0' }}"
                data-bs-toggle="modal" data-bs-target="#confirmModal">
                <i class="bi bi-envelope{{ $mailcowEnabled ? '-dash' : '-plus' }} me-1"></i>{{ $mailcowEnabled ? 'Remove Mailcow' : 'Add Mailcow' }}
              </button>
              @endif
              @endif
              @if($realm['realm'] !== 'master')
              <button type="button" class="btn btn-sm btn-danger"
                data-action="delete"
                data-realm="{{ $realm['realm'] }}"
                data-bs-toggle="modal" data-bs-target="#confirmModal">
                <i class="bi bi-trash me-1"></i>Delete
              </button>
              @endif
            </td>
          </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>
</div>

{{-- Confirmation modal --}}
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

<script>
document.getElementById('confirmModal').addEventListener('show.bs.modal', function (e) {
    const btn     = e.relatedTarget;
    const action  = btn.dataset.action;
    const realm   = btn.dataset.realm;
    const enabled = btn.dataset.enabled === '1';

    const modal   = this;
    const form    = document.getElementById('confirmForm');
    const method  = document.getElementById('confirmMethod');
    const title   = modal.querySelector('.modal-title');
    const body    = modal.querySelector('.modal-body');
    const confirm = document.getElementById('confirmBtn');

    if (action === 'delete') {
        form.action   = `/super/realms/${realm}`;
        method.value  = 'DELETE';
        title.textContent = `Delete realm "${realm}"`;
        body.innerHTML = `<div class="alert alert-danger mb-0"><i class="bi bi-exclamation-triangle-fill me-2"></i>
            This will permanently delete the realm and all its users and data in Keycloak. This cannot be undone.</div>`;
        confirm.className = 'btn btn-danger';
        confirm.textContent = 'Yes, delete';
    } else if (action === 'toggle-mailcow') {
        const mailcowEnabled = btn.dataset.mailcowEnabled === '1';
        form.action  = `/super/realms/${realm}/toggle-mailcow`;
        method.value = 'POST';

        if (mailcowEnabled) {
            title.textContent = `Remove Mailcow domain "${realm}"`;
            body.innerHTML = `<div class="alert alert-danger mb-0"><i class="bi bi-exclamation-triangle-fill me-2"></i>
                This will delete the domain and all its mailboxes from Mailcow. This cannot be undone.</div>`;
            confirm.className = 'btn btn-danger';
            confirm.textContent = 'Yes, remove';
            // ensure no link_only hidden field lingers
            document.getElementById('linkOnlyField')?.remove();
        } else {
            title.textContent = `Add Mailcow domain "${realm}"`;
            body.innerHTML = `<div class="text-center py-3"><div class="spinner-border spinner-border-sm me-2"></div>Checking Mailcow...</div>`;
            confirm.className = 'btn btn-primary d-none';
            confirm.textContent = 'Yes, add';
            document.getElementById('linkOnlyField')?.remove();

            fetch(`/super/realms/${realm}/check-mailcow`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => r.json())
                .then(data => {
                    confirm.classList.remove('d-none');
                    if (data.exists) {
                        body.innerHTML = `<div class="alert alert-warning"><i class="bi bi-exclamation-triangle-fill me-2"></i>
                            Domain <strong>${realm}</strong> already exists in Mailcow. No changes will be made there &mdash; this will only link it in the database.</div>`;
                        confirm.className = 'btn btn-warning';
                        confirm.textContent = 'Yes, link it';
                        const hidden = document.createElement('input');
                        hidden.type = 'hidden'; hidden.name = 'link_only'; hidden.value = '1'; hidden.id = 'linkOnlyField';
                        form.appendChild(hidden);
                    } else {
                        body.innerHTML = `<p class="mb-0">This will create the domain <strong>${realm}</strong> in Mailcow.</p>`;
                        confirm.className = 'btn btn-primary';
                        confirm.textContent = 'Yes, create';
                    }
                })
                .catch(() => {
                    body.innerHTML = `<div class="alert alert-danger mb-0">Could not reach Mailcow to check domain status.</div>`;
                    confirm.classList.add('d-none');
                });
        }
    } else {
        form.action   = `/super/realms/${realm}/toggle`;
        method.value  = 'POST';
        const verb    = enabled ? 'disable' : 'enable';
        title.textContent = `${verb.charAt(0).toUpperCase() + verb.slice(1)} realm "${realm}"`;
        body.innerHTML = enabled
            ? `<div class="alert alert-warning mb-0"><i class="bi bi-exclamation-triangle-fill me-2"></i>
                Disabling this realm will prevent all users from logging in.</div>`
            : `<p class="mb-0">Users will be able to log in again once the realm is enabled.</p>`;
        confirm.className = enabled ? 'btn btn-warning' : 'btn btn-success';
        confirm.textContent = `Yes, ${verb}`;
    }
});
</script>
@endsection
