@extends('super.layout')

@section('page-title', 'Create Realm')

@section('content')
<div class="card" style="max-width: 600px;">
  <div class="card-header">
    <h3 class="card-title">New Realm</h3>
  </div>
  <div class="card-body">
    @if ($errors->any())
      <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('super.realms.store') }}">
      @csrf

      <h6 class="text-muted mb-3">Realm</h6>
      <div class="mb-3">
        <label class="form-label">Realm / Domain name</label>
        <input type="text" name="realm" class="form-control" value="{{ old('realm') }}"
               placeholder="e.g. contoso.nl" required />
        <small class="text-muted">Used as both the Keycloak realm name and the email domain mapping.</small>
      </div>

      <hr />
      <h6 class="text-muted mb-3">First Admin User</h6>

      <div class="row mb-3">
        <div class="col">
          <label class="form-label">First name</label>
          <input type="text" name="admin_firstname" class="form-control" value="{{ old('admin_firstname') }}" required />
        </div>
        <div class="col">
          <label class="form-label">Last name</label>
          <input type="text" name="admin_lastname" class="form-control" value="{{ old('admin_lastname') }}" required />
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label">Email</label>
        <div class="input-group">
          <input type="text" name="admin_local_part" class="form-control" id="admin_local_part"
                 value="{{ old('admin_local_part') }}" placeholder="admin" required
                 pattern="[a-zA-Z0-9_.\-]+" />
          <span class="input-group-text" id="email-domain-suffix">@<span id="realm-suffix">{{ old('realm', 'domain') }}</span></span>
        </div>
        <small class="text-muted">Username will be <span id="email-preview">admin@{{ old('realm', 'domain') }}</span></small>
      </div>

      <div class="mb-4">
        <label class="form-label">Password</label>
        <input type="password" name="admin_password" class="form-control" required minlength="8" />
      </div>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">Create Realm</button>
        <a href="{{ route('super.realms') }}" class="btn btn-secondary">Cancel</a>
      </div>
    </form>
  </div>
</div>
@endsection

@push('scripts')
<script>
  const realmInput = document.querySelector('input[name="realm"]');
  const suffix     = document.getElementById('realm-suffix');
  const preview    = document.getElementById('email-preview');
  const localPart  = document.getElementById('admin_local_part');

  function updateSuffix() {
    const domain = realmInput.value.trim() || 'domain';
    suffix.textContent  = domain;
    preview.textContent = (localPart.value.trim() || 'admin') + '@' + domain;
  }

  realmInput.addEventListener('input', updateSuffix);
  localPart.addEventListener('input', updateSuffix);
</script>
@endpush
