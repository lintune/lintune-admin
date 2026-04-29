@extends('install.layout')

@php
  $steps = [
    ['label' => 'Welcome',      'state' => 'done'],
    ['label' => 'Server type',  'state' => 'active'],
    ['label' => 'Configure',    'state' => 'pending'],
    ['label' => 'Install',      'state' => 'pending'],
    ['label' => 'Done',         'state' => 'pending'],
  ];
  $stepLabel = 'Step 2 of 5';
@endphp

@section('content')
<div class="mb-3">
  <a href="{{ route('install.welcome') }}" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left me-1"></i>Back
  </a>
</div>

<div class="card shadow-sm">
  <div class="card-body py-5 px-4">
    <h4 class="fw-bold mb-2 text-center">How are your services arranged?</h4>
    <p class="text-muted text-center mb-5">
      Services can all live on one beefy server, or be spread across multiple machines.
    </p>

    <div class="row g-3 justify-content-center">
      <div class="col-md-5">
        <a href="{{ route('install.configure', ['type' => 'single']) }}"
           class="card h-100 text-decoration-none border-primary border-2 text-start p-4 d-block">
          <h5 class="fw-semibold"><i class="bi bi-server me-2 text-primary"></i>All on one server</h5>
          <p class="text-muted small mb-0">
            Keycloak, Mailcow, and Nextcloud all run on the same machine.
            Requires a reasonably powerful server (4+ cores, 8+ GB RAM).
          </p>
        </a>
      </div>
      <div class="col-md-5">
        <a href="{{ route('install.configure', ['type' => 'multi']) }}"
           class="card h-100 text-decoration-none text-start p-4 d-block">
          <h5 class="fw-semibold"><i class="bi bi-diagram-3 me-2 text-secondary"></i>One service per server</h5>
          <p class="text-muted small mb-0">
            Each service runs on its own dedicated machine. Provide separate
            IP and SSH credentials per service.
          </p>
        </a>
      </div>
    </div>
  </div>
</div>
@endsection
