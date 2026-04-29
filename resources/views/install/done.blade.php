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
    <p class="text-muted mb-4">
      Keycloak is configured and Lintune is ready.<br>
      Log in to continue.
    </p>
    <a href="{{ route('super.login') }}" class="btn btn-primary btn-lg">
      <i class="bi bi-box-arrow-in-right me-2"></i>Go to Login
    </a>
  </div>
</div>

@if(count($log))
<div class="card shadow-sm">
  <div class="card-header">
    <a href="#installLog" data-bs-toggle="collapse" class="text-muted small text-decoration-none">
      <i class="bi bi-chevron-down me-1"></i>Installation log
    </a>
  </div>
  <div id="installLog" class="collapse">
    <div class="card-body p-0">
      <pre class="bg-dark text-light m-0 p-3 rounded-bottom" style="font-size:.78rem;max-height:400px;overflow-y:auto">{{ implode("\n", $log) }}</pre>
    </div>
  </div>
</div>
@endif
@endsection
