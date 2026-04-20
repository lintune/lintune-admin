<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Lintune – Super Admin</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" crossorigin="anonymous" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" crossorigin="anonymous" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@4.0.0-rc2/dist/css/adminlte.min.css" crossorigin="anonymous" />
  <style>.app-header { background-color: #343a40 !important; }
  .app-header .nav-link, .app-header .navbar-nav .nav-link { color: #fff !important; }
  .app-header .text-muted { color: #ccc !important; }</style>
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
<div class="app-wrapper">

  <nav class="app-header navbar navbar-expand bg-body">
    <div class="container-fluid">
      <ul class="navbar-nav">
        <li class="nav-item">
          <a class="nav-link" data-lte-toggle="sidebar" href="#"><i class="bi bi-list"></i></a>
        </li>
      </ul>
      <ul class="navbar-nav ms-auto">
        <li class="nav-item d-flex align-items-center me-3">
          <small class="text-muted" id="session-timer-wrap" style="display:none">Automatic logout in: <span id="session-timer" class="fw-bold">--:--</span></small>
        </li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
            <i class="bi bi-person-circle me-1"></i>{{ session('super_username') }}
            <span class="badge text-bg-dark ms-1">super</span>
          </a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li>
              <form method="POST" action="{{ route('super.logout') }}">
                @csrf
                <button type="submit" class="dropdown-item text-danger">
                  <i class="bi bi-box-arrow-right me-2"></i>Sign out
                </button>
              </form>
            </li>
          </ul>
        </li>
      </ul>
    </div>
  </nav>

  <aside class="app-sidebar bg-dark navbar-dark shadow">
    <div class="sidebar-brand">
      <a href="{{ route('super.realms') }}" class="brand-link">
        <span class="brand-text fw-bold">Lintune</span>
        <small class="d-block text-muted" style="font-size:.65rem">Super Admin</small>
      </a>
    </div>
    <div class="sidebar-wrapper">
      <nav class="mt-2">
        <ul class="nav sidebar-menu flex-column" data-lte-toggle="treeview">
          <li class="nav-item">
            <a href="{{ route('super.realms') }}" class="nav-link {{ request()->routeIs('super.realms*') ? 'active' : '' }}">
              <i class="nav-icon bi bi-globe"></i>
              <p>Realms</p>
            </a>
          </li>
          <li class="nav-item">
            <a href="{{ route('super.audit-logs') }}" class="nav-link {{ request()->routeIs('super.audit-logs') ? 'active' : '' }}">
              <i class="nav-icon bi bi-journal-text"></i>
              <p>Audit Log</p>
            </a>
          </li>
        </ul>
      </nav>
    </div>
  </aside>

  <main class="app-main">
    <div class="app-content-header">
      <div class="container-fluid">
        <h3 class="mb-0">@yield('page-title')</h3>
      </div>
    </div>
    <div class="app-content">
      <div class="container-fluid">
        @yield('content')
      </div>
    </div>
  </main>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@4.0.0-rc2/dist/js/adminlte.min.js" crossorigin="anonymous"></script>
@stack('scripts')
<script>
(function () {
    const INACTIVITY_LIMIT = 5 * 60;
    let expiresAt = {{ session('super_token_expires_at', 0) }};
    let lastActivity = Math.floor(Date.now() / 1000);
    const timerEl = document.getElementById('session-timer');
    const timerWrap = document.getElementById('session-timer-wrap');
    const checkUrl = '{{ route('super.session.check') }}';
    const loginUrl = '{{ route('super.login') }}';

    ['mousemove', 'keydown', 'mousedown', 'touchstart', 'scroll'].forEach(function (e) {
        document.addEventListener(e, function () { lastActivity = Math.floor(Date.now() / 1000); }, { passive: true });
    });

    function inactiveSeconds() {
        return Math.floor(Date.now() / 1000) - lastActivity;
    }

    let refreshing = false;
    function updateDisplay() {
        const idleSeconds = inactiveSeconds();
        const remaining = INACTIVITY_LIMIT - idleSeconds;
        if (idleSeconds >= INACTIVITY_LIMIT) {
            timerWrap.style.display = '';
            timerEl.textContent = '00:00';
            timerEl.classList.add('text-danger');
            if (!refreshing) { refreshing = true; doLogout(); }
            return;
        }
        if (remaining <= 60) {
            timerWrap.style.display = '';
            const m = String(Math.floor(remaining / 60)).padStart(2, '0');
            const s = String(remaining % 60).padStart(2, '0');
            timerEl.textContent = m + ':' + s;
            timerEl.classList.toggle('text-warning', remaining <= 60);
            timerEl.classList.toggle('text-danger', remaining <= 30);
        } else {
            timerWrap.style.display = 'none';
        }
    }

    async function refresh() {
        if (inactiveSeconds() >= INACTIVITY_LIMIT) { doLogout(); return; }
        try {
            const res = await fetch(checkUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const data = await res.json();
            if (!data.valid) { window.location.href = loginUrl; return; }
            expiresAt = data.expires_at;
            refreshing = false;
        } catch (e) {
            window.location.href = loginUrl;
        }
    }

    function doLogout() {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '{{ route('super.logout') }}';
        const csrf = document.createElement('input');
        csrf.type = 'hidden'; csrf.name = '_token'; csrf.value = '{{ csrf_token() }}';
        form.appendChild(csrf);
        document.body.appendChild(form);
        form.submit();
    }

    updateDisplay();
    setInterval(updateDisplay, 1000);
    setInterval(refresh, 30000);
})();
</script>
</body>
</html>
