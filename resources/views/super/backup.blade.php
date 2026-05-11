@extends('super.layout')

@section('page-title', 'Backup')

@section('content')

@if (session('success'))
  <div class="alert alert-success" style="max-width:700px">{{ session('success') }}</div>
@endif
@if ($errors->any())
  <div class="alert alert-danger" style="max-width:700px">{{ $errors->first() }}</div>
@endif

{{-- Last backup status --}}
<div class="card mb-4" style="max-width:700px">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h3 class="card-title mb-0">Last Backup</h3>
    @if ($enabled)
      <form method="POST" action="{{ route('super.backup.trigger') }}" data-no-spinner>
        @csrf
        <button class="btn btn-sm btn-outline-primary">
          <i class="bi bi-play-fill me-1"></i>Run now
        </button>
      </form>
    @endif
  </div>
  <div class="card-body">
    @if (!$last_status)
      <p class="text-muted mb-0">No backup has run yet.</p>
    @else
      <p class="text-muted small mb-3">{{ $last_status['timestamp'] }}</p>
      @foreach ($last_status['servers'] ?? [] as $id => $server)
        <div class="mb-3">
          <strong>{{ $server['label'] }}</strong>
          <div class="mt-1">
            @foreach ($server['services'] ?? [] as $service => $result)
              <span class="badge me-1 {{ $result['ok'] ? 'bg-success' : 'bg-danger' }}">
                {{ $service }}
                @if ($result['ok'])
                  &nbsp;{{ $result['size_mb'] }}MB
                @else
                  &nbsp;{{ $result['error'] ?? 'failed' }}
                @endif
              </span>
            @endforeach
          </div>
        </div>
      @endforeach
    @endif
  </div>
</div>

{{-- Settings --}}
<div class="card" style="max-width:700px">
  <div class="card-header">
    <h3 class="card-title">Backup Settings</h3>
  </div>
  <div class="card-body">
    <form method="POST" action="{{ route('super.backup.update') }}">
      @csrf
      @method('PUT')

      <div class="mb-4 d-flex align-items-center justify-content-between">
        <div>
          <strong>Enable automated backups</strong>
          <div class="text-muted small">Backs up Keycloak, Mailcow, and Nextcloud on a cron schedule.</div>
        </div>
        <div class="form-check form-switch ms-4">
          <input class="form-check-input" type="checkbox" name="enabled" value="1"
                 id="backupEnabled" {{ $enabled ? 'checked' : '' }} style="font-size:1.4rem">
        </div>
      </div>

      <hr />

      <div class="mb-3">
        <label class="form-label">Schedule (cron expression)</label>
        <input type="text" name="schedule" class="form-control font-monospace"
               value="{{ old('schedule', $schedule) }}" placeholder="0 2 * * *" required />
        <small class="text-muted">Default: <code>0 2 * * *</code> (daily at 02:00). Changes take effect within 30 seconds.</small>
      </div>

      <button type="submit" class="btn btn-primary">Save</button>
    </form>
  </div>
</div>

{{-- SSH public key --}}
@if ($public_key)
<div class="card mt-4" style="max-width:700px">
  <div class="card-header">
    <h3 class="card-title">Backup SSH Public Key</h3>
  </div>
  <div class="card-body">
    <p class="text-muted small">This key is installed on each service server during setup. Copy it if you need to add it to a server manually.</p>
    <textarea class="form-control font-monospace" rows="3" readonly>{{ $public_key }}</textarea>
  </div>
</div>
@endif

@endsection
