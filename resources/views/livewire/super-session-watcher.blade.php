<div
    x-data="{
        lastActivity: Math.floor(Date.now() / 1000),
        inactivityLimit: 5 * 60,
        timerText: '',
        timerVisible: false,
        timerDanger: false,
        timerWarning: false,
        refreshing: false,

        init() {
            ['mousemove', 'keydown', 'mousedown', 'touchstart', 'scroll'].forEach(e => {
                document.addEventListener(e, () => { this.lastActivity = Math.floor(Date.now() / 1000); }, { passive: true });
            });
            setInterval(() => this.updateDisplay(), 1000);
            setInterval(() => this.doRefresh(), 30000);
        },

        inactiveSeconds() {
            return Math.floor(Date.now() / 1000) - this.lastActivity;
        },

        updateDisplay() {
            const idle = this.inactiveSeconds();
            const remaining = this.inactivityLimit - idle;
            if (idle >= this.inactivityLimit) {
                this.timerVisible = true;
                this.timerText = '00:00';
                this.timerDanger = true;
                if (!this.refreshing) { this.refreshing = true; this.doLogout(); }
                return;
            }
            if (remaining <= 60) {
                const m = String(Math.floor(remaining / 60)).padStart(2, '0');
                const s = String(remaining % 60).padStart(2, '0');
                this.timerText = m + ':' + s;
                this.timerVisible = true;
                this.timerWarning = remaining <= 60;
                this.timerDanger = remaining <= 30;
            } else {
                this.timerVisible = false;
            }
        },

        async doRefresh() {
            if (this.inactiveSeconds() >= this.inactivityLimit) { this.doLogout(); return; }
            try {
                const res  = await fetch('{{ $checkUrl }}', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                const data = await res.json();
                if (!data.valid) { window.location.href = '{{ route('super.login') }}'; return; }
                this.refreshing = false;
            } catch (e) {
                window.location.href = '{{ route('super.login') }}';
            }
        },

        doLogout() {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '{{ $logoutUrl }}';
            const csrf = document.createElement('input');
            csrf.type = 'hidden'; csrf.name = '_token'; csrf.value = '{{ $csrfToken }}';
            form.appendChild(csrf);
            document.body.appendChild(form);
            form.submit();
        },
    }"
>
    <span
        x-show="timerVisible"
        x-text="'Logout in: ' + timerText"
        :class="{ 'text-danger-400': timerDanger, 'text-warning-400': timerWarning && !timerDanger }"
        class="text-sm font-medium px-3"
    ></span>
</div>
