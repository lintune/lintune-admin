<?php

namespace App\Livewire;

use Livewire\Component;

class SuperSessionWatcher extends Component
{
    public string $checkUrl;
    public string $logoutUrl;
    public string $csrfToken;
    public int    $expiresAt;

    public function mount(): void
    {
        $this->checkUrl  = route('super.session.check');
        $this->logoutUrl = route('super.logout');
        $this->csrfToken = csrf_token();
        $this->expiresAt = (int) session('super_token_expires_at', 0);
    }

    public function render()
    {
        return view('livewire.super-session-watcher');
    }
}
