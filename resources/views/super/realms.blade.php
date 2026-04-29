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
            <th>Status</th>
            <th>Users</th>
            @if($mailcowConfigured || $nextcloudConfigured)<th>Services</th>@endif
            <th></th>
          </tr>
        </thead>
        <tbody>
          @foreach ($realms as $realm)
          @php
            $enabled          = $realm['enabled'] ?? false;
            $map              = $domainMaps[$realm['realm']] ?? null;
            $mailcowEnabled   = $map?->mailcow_enabled ?? false;
            $nextcloudEnabled = $map?->nextcloud_enabled ?? false;
            $maxUsers         = $map?->max_users;
            $maxMailbox       = $map?->max_mailbox_users;
            $maxNextcloud     = $map?->max_nextcloud_users;
            $kcCount          = $keycloakCounts[$realm['realm']] ?? 0;
            $mbCount          = $mailboxCounts[$realm['realm']] ?? 0;
            $ncCount          = $nextcloudCounts[$realm['realm']] ?? 0;
          @endphp
          <tr>
            <td>
              <div>{{ $realm['realm'] }}</div>
              @if($realm['displayName'] ?? false)
                <small class="text-muted">{{ $realm['displayName'] }}</small>
              @endif
            </td>
            <td>
              <span class="badge text-bg-{{ $enabled ? 'success' : 'secondary' }}">
                {{ $enabled ? 'Enabled' : 'Disabled' }}
              </span>
            </td>
            <td>
              <small class="text-muted d-block">
                <i class="bi bi-people me-1"></i>{{ $kcCount }}{{ $maxUsers ? '/'.$maxUsers : '' }}
              </small>
              @if($mailcowEnabled)
              <small class="text-muted d-block">
                <i class="bi bi-envelope me-1"></i>{{ $mbCount }}{{ $maxMailbox ? '/'.$maxMailbox : '' }}
              </small>
              @endif
              @if($nextcloudEnabled)
              <small class="text-muted d-block">
                <i class="bi bi-cloud me-1"></i>{{ $ncCount }}{{ $maxNextcloud ? '/'.$maxNextcloud : '' }}
              </small>
              @endif
            </td>
            @if($mailcowConfigured || $nextcloudConfigured)
            <td>
              @if($mailcowConfigured)
                <i class="bi {{ $mailcowEnabled ? 'bi-envelope-check-fill text-success' : 'bi-envelope text-secondary' }} fs-5 me-2"
                   title="Mailcow {{ $mailcowEnabled ? 'enabled' : 'not enabled' }}"></i>
              @endif
              @if($nextcloudConfigured)
                <i class="bi {{ $nextcloudEnabled ? 'bi-cloud-check-fill text-success' : 'bi-cloud text-secondary' }} fs-5"
                   title="Nextcloud {{ $nextcloudEnabled ? 'enabled' : 'not enabled' }}"></i>
              @endif
            </td>
            @endif
            <td class="text-end pe-3">
              <a href="{{ route('super.realms.edit', $realm['realm']) }}" class="btn btn-sm btn-outline-secondary me-1">
                <i class="bi bi-pencil"></i>
              </a>
              <button type="button" class="btn btn-sm {{ $enabled ? 'btn-warning' : 'btn-success' }}"
                data-realm="{{ $realm['realm'] }}"
                data-enabled="{{ $enabled ? '1' : '0' }}"
                data-bs-toggle="modal" data-bs-target="#confirmModal">
                <i class="bi bi-{{ $enabled ? 'pause-circle' : 'play-circle' }}"></i>
              </button>
            </td>
          </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>
</div>

{{-- Confirm modal (toggle + delete) --}}
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
document.getElementById('confirmModal').addEventListener('show.bs.modal', function (e) {
  const btn     = e.relatedTarget;
  const realm   = btn.dataset.realm;
  const enabled = btn.dataset.enabled === '1';
  const verb    = enabled ? 'disable' : 'enable';

  document.getElementById('confirmForm').action    = `/super/realms/${realm}/toggle`;
  document.getElementById('confirmMethod').value   = 'POST';
  this.querySelector('.modal-title').textContent   = `${verb.charAt(0).toUpperCase() + verb.slice(1)} realm "${realm}"`;
  this.querySelector('.modal-body').innerHTML      = enabled
    ? `<div class="alert alert-warning mb-0"><i class="bi bi-exclamation-triangle-fill me-2"></i>Disabling this realm will prevent all users from logging in.</div>`
    : `<p class="mb-0">Users will be able to log in again once the realm is enabled.</p>`;
  const btn2 = document.getElementById('confirmBtn');
  btn2.className   = enabled ? 'btn btn-warning' : 'btn btn-success';
  btn2.textContent = `Yes, ${verb}`;
});
</script>
@endpush
@endsection
