@extends('install.layout')

@php
  $steps = [
    ['label' => 'Welcome',   'state' => 'done'],
    ['label' => 'Configure', 'state' => 'done'],
    ['label' => 'Install',   'state' => 'done'],
    ['label' => 'Done',      'state' => 'active'],
  ];
  $stepLabel = 'Complete';
@endphp

@section('content')
<div class="card shadow-sm text-center mb-4">
  <div class="card-body py-5">
    <i class="bi bi-check-circle-fill text-success" style="font-size:3.5rem"></i>
    <h2 class="mt-3 fw-bold">Setup Complete</h2>
    <p class="text-muted mb-0">
      Keycloak is configured and Lintune is ready.
    </p>
  </div>
</div>

@if($kcUrl)
<div class="card shadow-sm mb-4">
  <div class="card-body">
    <h6 class="fw-semibold mb-3"><i class="bi bi-shield-lock me-2 text-primary"></i>What was configured</h6>
    <table class="table table-sm mb-0">
      <tr>
        <td class="text-muted" style="width:160px">Keycloak URL</td>
        <td><code>{{ $kcUrl }}</code></td>
      </tr>
      @if(!empty($adminUsername))
      <tr>
        <td class="text-muted">Master admin</td>
        <td>
          <code>{{ $adminUsername }}</code>
          <span class="text-muted small ms-2">— use this to log in to the Keycloak admin UI</span>
        </td>
      </tr>
      @endif
      <tr>
        <td class="text-muted">Admin client</td>
        <td><code>lintune-admin</code> (created in master realm)</td>
      </tr>
      <tr>
        <td class="text-muted">Service account</td>
        <td><code>lintune-service</code> (admin role in master realm)</td>
      </tr>
    </table>
    <div class="mt-3 small text-muted">
      <i class="bi bi-info-circle me-1"></i>
      The Keycloak URL is saved in Settings and can be updated there if needed.
      Mailcow and Nextcloud can be connected later via <strong>Settings</strong> once you're logged in.
    </div>
  </div>
</div>
@endif

<div class="text-center mb-4">
  <a href="{{ route('super.login') }}" class="btn btn-primary btn-lg">
    <i class="bi bi-box-arrow-in-right me-2"></i>Go to Login
  </a>
</div>

@if(count($log))
<div class="card shadow-sm">
  <div class="card-header">
    <a href="#installLog" data-bs-toggle="collapse" class="text-muted small text-decoration-none">
      <i class="bi bi-chevron-down me-1"></i>Installation log
    </a>
  </div>
  <div id="installLog" class="collapse">
    <pre class="bg-dark text-light m-0 p-3 rounded-bottom" style="font-size:.75rem;max-height:400px;overflow-y:auto">{{ implode("\n", $log) }}</pre>
  </div>
</div>
@endif
@endsection
