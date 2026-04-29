@extends('install.layout')

@php
  $steps = [
    ['label' => 'Welcome',   'state' => 'active'],
    ['label' => 'Configure', 'state' => 'pending'],
    ['label' => 'Install',   'state' => 'pending'],
    ['label' => 'Done',      'state' => 'pending'],
  ];
  $stepLabel = 'Step 1 of 4';
@endphp

@section('content')
<div class="card shadow-sm">
  <div class="card-body text-center py-5 px-4">
    <i class="bi bi-stars text-primary" style="font-size:3rem"></i>
    <h2 class="mt-3 mb-2 fw-bold">Welcome to Lintune</h2>
    <p class="text-muted mb-5" style="max-width:500px;margin:0 auto">
      This wizard will guide you through the initial platform setup.
      You can install all required services automatically, or configure them manually
      if you already have everything running.
    </p>

    <div class="row g-3 justify-content-center">
      <div class="col-md-5">
        <a href="{{ route('install.server-type') }}"
           class="card h-100 text-decoration-none border-primary border-2 text-start p-4 d-block">
          <div class="mb-3">
            <span class="badge bg-primary">Recommended</span>
          </div>
          <h5 class="fw-semibold"><i class="bi bi-magic me-2 text-primary"></i>Install for me</h5>
          <p class="text-muted small mb-0">
            SSH into your server(s) and automatically install Docker, Keycloak, and
            optionally Mailcow and Nextcloud.
          </p>
        </a>
      </div>
      <div class="col-md-5">
        <a href="{{ route('install.manual') }}"
           class="card h-100 text-decoration-none text-start p-4 d-block">
          <div class="mb-3">
            <span class="badge bg-secondary">Advanced</span>
          </div>
          <h5 class="fw-semibold"><i class="bi bi-terminal me-2 text-secondary"></i>I'll set it up myself</h5>
          <p class="text-muted small mb-0">
            Connect to an existing Keycloak instance. You configure Mailcow and Nextcloud
            later in Settings.
          </p>
        </a>
      </div>
    </div>
  </div>
</div>
@endsection
