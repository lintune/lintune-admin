<?php

namespace App\Http\Controllers\Super;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\BackupService;
use Illuminate\Http\Request;

class BackupController extends Controller
{
    public function __construct(private BackupService $backup) {}

    public function index()
    {
        return view('super.backup', [
            'enabled'     => $this->backup->isEnabled(),
            'schedule'    => $this->backup->getSchedule(),
            'last_status' => $this->backup->getLastStatus(),
            'public_key'  => $this->backup->getPublicKey(),
        ]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'enabled'  => 'boolean',
            'schedule' => ['required', 'string', 'regex:/^(\S+\s+){4}\S+$/'],
        ]);

        $enabled = (bool) $request->input('enabled', false);
        $this->backup->setEnabled($enabled);
        $this->backup->setSchedule($request->schedule);

        AuditLogger::log('backup.settings_updated');

        return back()->with('success', 'Backup settings saved.');
    }

    public function trigger()
    {
        $this->backup->triggerNow();

        AuditLogger::log('backup.triggered_manually');

        return back()->with('success', 'Backup started.');
    }
}
