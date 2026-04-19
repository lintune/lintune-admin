@extends('super.layout')

@section('page-title', 'Audit Log')

@section('content')
<div class="card">
  <div class="card-header">
    <form method="GET" action="{{ route('super.audit-logs') }}" class="row g-2 align-items-end">
      <div class="col-auto">
        <input type="text" name="username" class="form-control form-control-sm" placeholder="Username" value="{{ request('username') }}">
      </div>
      <div class="col-auto">
        <input type="text" name="action" class="form-control form-control-sm" placeholder="Action" value="{{ request('action') }}">
      </div>
      <div class="col-auto">
        <input type="date" name="date" class="form-control form-control-sm" value="{{ request('date') }}">
      </div>
      <div class="col-auto">
        <select name="app" class="form-select form-select-sm">
          <option value="">All sources</option>
          <option value="admin" {{ request('app') === 'admin' ? 'selected' : '' }}>Admin</option>
          <option value="dash" {{ request('app') === 'dash' ? 'selected' : '' }}>Dash</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Filter</button>
        <a href="{{ route('super.audit-logs') }}" class="btn btn-secondary btn-sm ms-1">Clear</a>
      </div>
    </form>
  </div>
  <div class="card-body p-0">
    @if ($logs->isEmpty())
      <p class="text-center p-4 text-muted">No log entries found.</p>
    @else
      <table class="table table-striped table-hover mb-0 align-middle">
        <thead>
          <tr>
            <th>Date / Time</th>
            <th>Source</th>
            <th>User</th>
            <th>Action</th>
            <th>Realm</th>
            <th>Detail</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($logs as $log)
          <tr>
            <td class="text-nowrap text-muted small">{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
            <td><span class="badge text-bg-{{ $log->app === 'admin' ? 'dark' : 'primary' }}">{{ $log->app }}</span></td>
            <td>{{ $log->username }}</td>
            <td><span class="badge text-bg-secondary">{{ $log->action }}</span></td>
            <td>{{ $log->realm ?? '–' }}</td>
            <td class="text-muted small">{{ $log->detail ?? '' }}</td>
          </tr>
          @endforeach
        </tbody>
      </table>
      <div class="p-3">
        {{ $logs->links() }}
      </div>
    @endif
  </div>
</div>
@endsection
