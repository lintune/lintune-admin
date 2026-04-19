<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Lintune – Super Admin</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" crossorigin="anonymous" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" crossorigin="anonymous" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@4.0.0-rc2/dist/css/adminlte.min.css" crossorigin="anonymous" />
</head>
<body class="login-page bg-body-secondary">

<div class="login-box">
  <div class="card card-outline card-dark">
    <div class="card-header text-center">
      <span class="h1 fw-bold">Lintune</span>
      <p class="text-muted mb-0 small">Super Admin</p>
    </div>
    <div class="card-body">
      @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
      @endif

      <a href="{{ route('super.login') }}" class="btn btn-dark w-100">
        <i class="bi bi-box-arrow-in-right me-2"></i>Sign in with Keycloak
      </a>
    </div>
  </div>
</div>

</body>
</html>
