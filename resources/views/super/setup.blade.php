<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Lintune – {{ $repairMode ? 'Repair Setup' : 'Initial Setup' }}</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" crossorigin="anonymous" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" crossorigin="anonymous" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@4.0.0-rc2/dist/css/adminlte.min.css" crossorigin="anonymous" />
</head>
<body class="login-page bg-body-secondary">

<div class="login-box" style="width:440px">
  <div class="card card-outline card-{{ $repairMode ? 'warning' : 'dark' }}">
    <div class="card-header text-center">
      <span class="h1 fw-bold">Lintune</span>
      <p class="text-muted mb-0 small">{{ $repairMode ? 'Repair Setup' : 'Initial Setup' }}</p>
    </div>
    <div class="card-body">

      @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
      @endif

      @if ($repairMode)
        @if ($serviceAccountOk)
          <div class="alert alert-success">
            <i class="bi bi-check-circle me-2"></i>
            Service account is reachable. Setup can be completed without recreating it.
          </div>
          <form method="POST" action="{{ route('super.setup.run') }}">
            @csrf
            <input type="hidden" name="repair" value="1">
            <input type="hidden" name="username" value="">
            <input type="hidden" name="password" value="">
            <p class="text-muted small mb-3">Enter your Keycloak master admin credentials to verify and complete setup.</p>
            <div class="input-group mb-3">
              <input type="text" name="username" class="form-control" placeholder="Keycloak admin username" required />
              <span class="input-group-text"><i class="bi bi-person"></i></span>
            </div>
            <div class="input-group mb-3">
              <input type="password" name="password" class="form-control" placeholder="Keycloak admin password" required />
              <span class="input-group-text"><i class="bi bi-lock"></i></span>
            </div>
            <button type="submit" class="btn btn-success w-100">
              <i class="bi bi-check-lg me-2"></i>Complete Setup
            </button>
          </form>
        @else
          <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle me-2"></i>
            Service account is not reachable — it may have been deleted or its password changed. Enter your Keycloak master admin credentials to recreate it.
          </div>
          <form method="POST" action="{{ route('super.setup.run') }}">
            @csrf
            <input type="hidden" name="repair" value="1">
            <div class="input-group mb-3">
              <input type="text" name="username" class="form-control" placeholder="Keycloak admin username" required />
              <span class="input-group-text"><i class="bi bi-person"></i></span>
            </div>
            <div class="input-group mb-3">
              <input type="password" name="password" class="form-control" placeholder="Keycloak admin password" required />
              <span class="input-group-text"><i class="bi bi-lock"></i></span>
            </div>
            <button type="submit" class="btn btn-warning w-100">
              <i class="bi bi-arrow-clockwise me-2"></i>Recreate Service Account
            </button>
          </form>
        @endif

      @else
        <p class="text-muted small mb-3">Enter your Keycloak master realm admin credentials. This will:</p>
        <ul class="text-muted small mb-3">
          <li>Create the <code>lintune-admin</code> OIDC client in the master realm</li>
          <li>Create a broker realm with a generated name</li>
          <li>Create a dedicated <code>lintune-service</code> account for Admin API calls</li>
          <li>Save the configuration and lock this setup page</li>
        </ul>

        <form method="POST" action="{{ route('super.setup.run') }}">
          @csrf
          <input type="hidden" name="repair" value="0">
          <div class="input-group mb-3">
            <input type="text" name="username" class="form-control" placeholder="Keycloak admin username"
                   value="{{ old('username') }}" required autocomplete="username" />
            <span class="input-group-text"><i class="bi bi-person"></i></span>
          </div>
          <div class="input-group mb-3">
            <input type="password" name="password" class="form-control" placeholder="Keycloak admin password"
                   required autocomplete="current-password" />
            <span class="input-group-text"><i class="bi bi-lock"></i></span>
          </div>
          <button type="submit" class="btn btn-dark w-100">
            <i class="bi bi-gear me-2"></i>Run Setup
          </button>
        </form>
      @endif

    </div>
  </div>
</div>

</body>
</html>
