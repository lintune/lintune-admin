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

    @if (empty($realms))
      <p class="text-center p-4 text-muted">No realms found.</p>
    @else
      <table class="table table-striped table-hover mb-0">
        <thead>
          <tr>
            <th>Realm</th>
            <th>Display Name</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($realms as $realm)
          <tr>
            <td>{{ $realm['realm'] }}</td>
            <td>{{ $realm['displayName'] ?? '–' }}</td>
            <td>
              <span class="badge text-bg-{{ ($realm['enabled'] ?? false) ? 'success' : 'secondary' }}">
                {{ ($realm['enabled'] ?? false) ? 'Enabled' : 'Disabled' }}
              </span>
            </td>
          </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>
</div>
@endsection
