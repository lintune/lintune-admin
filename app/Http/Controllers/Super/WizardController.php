<?php

namespace App\Http\Controllers\Super;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\AuditLogger;
use App\Services\SshInstaller;
use Illuminate\Http\Request;

class WizardController extends Controller
{
    // ── Step: choose path ─────────────────────────────────────────────────────

    public function welcome()
    {
        if (Setting::get('wizard.complete')) {
            return redirect()->route('super.realms');
        }
        return view('super.wizard', ['step' => 'choose']);
    }

    // ── Step: server type ─────────────────────────────────────────────────────

    public function serverType()
    {
        return view('super.wizard', ['step' => 'server-type']);
    }

    // ── Step: single-server form ──────────────────────────────────────────────

    public function singleServer()
    {
        return view('super.wizard', ['step' => 'single']);
    }

    // ── Step: multi-server form ───────────────────────────────────────────────

    public function multiServer()
    {
        return view('super.wizard', ['step' => 'multi']);
    }

    // ── Install: single server ────────────────────────────────────────────────

    public function installSingle(Request $request)
    {
        $request->validate([
            'ssh_host' => 'required|string',
            'ssh_user' => 'required|string',
            'ssh_pass' => 'required|string',
        ]);

        set_time_limit(0);
        $log = [];

        try {
            $host = $request->ssh_host === '__local__' ? '127.0.0.1' : $request->ssh_host;
            $ssh  = new SshInstaller($host, $request->ssh_user, $request->ssh_pass);
            $ssh->ensureDocker();

            if ($request->boolean('install_mailcow') && $request->filled('mailcow_hostname')) {
                $ssh->installMailcow($request->mailcow_hostname);
                Setting::set('mailcow.url', "https://{$request->mailcow_hostname}");
            }
            if ($request->boolean('install_nextcloud')) {
                $ssh->installNextcloud();
                Setting::set('nextcloud.url', "http://{$host}:11000");
            }
            $log = $ssh->getLog();
        } catch (\Throwable $e) {
            return back()->withErrors(['ssh' => 'Installation failed: ' . $e->getMessage()])
                         ->withInput($request->except('ssh_pass'));
        }

        return $this->finish($log);
    }

    // ── Install: multi server ─────────────────────────────────────────────────

    public function installMulti(Request $request)
    {
        set_time_limit(0);
        $log = [];

        try {
            if ($request->boolean('install_mailcow') && $request->filled('mc_host', 'mailcow_hostname')) {
                $ssh = new SshInstaller($request->mc_host, $request->mc_user, $request->mc_pass);
                $ssh->ensureDocker();
                $ssh->installMailcow($request->mailcow_hostname);
                $log = array_merge($log, $ssh->getLog());
                Setting::set('mailcow.url', "https://{$request->mailcow_hostname}");
            }

            if ($request->boolean('install_nextcloud') && $request->filled('nc_host')) {
                $ssh = new SshInstaller($request->nc_host, $request->nc_user, $request->nc_pass);
                $ssh->ensureDocker();
                $ssh->installNextcloud();
                $log = array_merge($log, $ssh->getLog());
                Setting::set('nextcloud.url', "http://{$request->nc_host}:11000");
            }
        } catch (\Throwable $e) {
            return back()->withErrors(['ssh' => 'Installation failed: ' . $e->getMessage()])
                         ->withInput($request->except('mc_pass', 'nc_pass'));
        }

        return $this->finish($log);
    }

    // ── Skip (manual config later) ────────────────────────────────────────────

    public function skip()
    {
        return $this->finish([]);
    }

    // ── Reset wizard (dev / settings page) ───────────────────────────────────

    public function reset()
    {
        Setting::where('key', 'wizard.complete')->delete();
        AuditLogger::log('wizard.reset');
        return redirect()->route('super.settings')->with('success', 'First-run wizard reset.');
    }

    // ── Done ──────────────────────────────────────────────────────────────────

    public function done()
    {
        $log = session('wizard_log', []);
        return view('super.wizard', ['step' => 'done', 'log' => $log]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function finish(array $log): \Illuminate\Http\RedirectResponse
    {
        Setting::set('wizard.complete', '1');
        AuditLogger::log('wizard.completed');
        session(['wizard_log' => $log]);
        return redirect()->route('super.wizard.done');
    }
}
