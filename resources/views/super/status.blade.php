@extends('super.layout')

@section('page-title', 'Service Status')

@section('content')
<div class="row mb-3">
  <div class="col">
    <p class="text-muted mb-0">Live status of all monitored services. Refreshes every 30 seconds.</p>
  </div>
  <div class="col-auto">
    <button class="btn btn-sm btn-outline-secondary" id="refresh-btn">
      <i class="bi bi-arrow-clockwise me-1"></i>Refresh
    </button>
  </div>
</div>

<div id="status-grid" class="row g-3">
  @if(empty($monitors))
    <div class="col-12" id="no-data-notice">
      <div class="alert alert-secondary mb-0">
        <i class="bi bi-info-circle me-2"></i>No monitors found. Uptime Kuma may not be configured yet, or no monitors have been added.
      </div>
    </div>
  @else
    @foreach($monitors as $monitor)
      @php
        $status   = $monitor['status'] ?? 2;
        $badgeCls = match((int)$status) { 1 => 'success', 0 => 'danger', 3 => 'warning', default => 'secondary' };
        $label    = match((int)$status) { 1 => 'Up', 0 => 'Down', 3 => 'Maintenance', default => 'Unknown' };
        $iconCls  = match((int)$status) { 1 => 'bi-check-circle-fill text-success', 0 => 'bi-x-circle-fill text-danger', 3 => 'bi-tools text-warning', default => 'bi-dash-circle text-secondary' };
      @endphp
      <div class="col-sm-6 col-lg-4 col-xl-3">
        <div class="card h-100 border-0 shadow-sm">
          <div class="card-body d-flex align-items-start gap-3">
            <i class="bi {{ $iconCls }} fs-2 flex-shrink-0 mt-1"></i>
            <div class="flex-grow-1 min-w-0">
              <div class="fw-semibold text-truncate" title="{{ $monitor['name'] }}">{{ $monitor['name'] }}</div>
              <div class="text-muted small text-truncate" title="{{ $monitor['url'] ?? '' }}">{{ $monitor['url'] ?? '' }}</div>
            </div>
            <span class="badge text-bg-{{ $badgeCls }} flex-shrink-0 align-self-start mt-1">{{ $label }}</span>
          </div>
        </div>
      </div>
    @endforeach
  @endif
</div>
@endsection

@push('scripts')
<script>
(function () {
    const colors  = { 0: '#dc3545', 1: '#28a745', 2: '#6c757d', 3: '#ffc107' };
    const labels  = { 0: 'Down', 1: 'Up', 2: 'Unknown', 3: 'Maintenance' };
    const icons   = { 0: 'bi-x-circle-fill text-danger', 1: 'bi-check-circle-fill text-success', 2: 'bi-dash-circle text-secondary', 3: 'bi-tools text-warning' };
    const badges  = { 0: 'danger', 1: 'success', 2: 'secondary', 3: 'warning' };

    function buildCard(m) {
        const status  = m.status ?? 2;
        const icon    = icons[status] ?? icons[2];
        const label   = labels[status] ?? 'Unknown';
        const badge   = badges[status] ?? 'secondary';
        return `<div class="col-sm-6 col-lg-4 col-xl-3">
  <div class="card h-100 border-0 shadow-sm">
    <div class="card-body d-flex align-items-start gap-3">
      <i class="bi ${icon} fs-2 flex-shrink-0 mt-1"></i>
      <div class="flex-grow-1 min-w-0">
        <div class="fw-semibold text-truncate" title="${m.name}">${m.name}</div>
        <div class="text-muted small text-truncate" title="${m.url || ''}">${m.url || ''}</div>
      </div>
      <span class="badge text-bg-${badge} flex-shrink-0 align-self-start mt-1">${label}</span>
    </div>
  </div>
</div>`;
    }

    function refresh() {
        fetch('{{ route("super.status") }}')
            .then(r => r.json())
            .then(data => {
                const grid = document.getElementById('status-grid');
                if (!data.length) {
                    grid.innerHTML = `<div class="col-12"><div class="alert alert-secondary mb-0"><i class="bi bi-info-circle me-2"></i>No monitors found.</div></div>`;
                    return;
                }
                grid.innerHTML = data.map(buildCard).join('');
            })
            .catch(() => {});
    }

    document.getElementById('refresh-btn').addEventListener('click', refresh);
    setInterval(refresh, 30000);
})();
</script>
@endpush
