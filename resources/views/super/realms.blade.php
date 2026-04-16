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
            <th></th>
          </tr>
        </thead>
        <tbody>
          @foreach ($realms as $realm)
          @php $enabled = $realm['enabled'] ?? false; @endphp
          <tr>
            <td>{{ $realm['realm'] }}</td>
            <td>{{ $realm['displayName'] ?? '–' }}</td>
            <td>
              <span class="badge text-bg-{{ $enabled ? 'success' : 'secondary' }}">
                {{ $enabled ? 'Enabled' : 'Disabled' }}
              </span>
            </td>
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
