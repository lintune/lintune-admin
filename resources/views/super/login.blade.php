<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Lintune – Super Admin</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@4.0.0-rc2/dist/css/adminlte.min.css" />
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

      <form method="POST" action="{{ route('super.login.submit') }}">
        @csrf
        <div class="input-group mb-3">
          <input type="text" name="username" class="form-control" placeholder="Username"
                 value="{{ old('username') }}" required autocomplete="username" />
          <span class="input-group-text"><i class="bi bi-person"></i></span>
        </div>
        <div class="input-group mb-3">
          <input type="password" name="password" class="form-control" placeholder="Password"
                 required autocomplete="current-password" />
          <span class="input-group-text"><i class="bi bi-lock"></i></span>
        </div>
        <button type="submit" class="btn btn-dark w-100">Sign in</button>
      </form>
    </div>
  </div>
</div>

</body>
</html>
