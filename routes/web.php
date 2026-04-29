<?php

use App\Http\Controllers\InstallController;
use App\Http\Controllers\Super\SettingsController;
use App\Http\Controllers\Super\AuditLogController;
use App\Http\Controllers\Super\SetupController;
use App\Http\Controllers\Super\SuperAuthController;
use App\Http\Controllers\Super\SuperRealmController;
use App\Http\Controllers\Super\WizardController;
use App\Http\Middleware\RequireSuperAuth;
use App\Http\Middleware\SetupComplete;
use App\Http\Middleware\WizardComplete;
use Illuminate\Support\Facades\Route;


Route::get('/', fn() => redirect()->route('super.login'));

// ── Pre-auth installer (unauthenticated, blocked once setup is complete) ──────
Route::prefix('install')->name('install.')->group(function () {
    Route::get('/',           [InstallController::class, 'welcome'])->name('welcome');
    Route::get('/server-type',[InstallController::class, 'serverType'])->name('server-type');
    Route::get('/configure',  [InstallController::class, 'configure'])->name('configure');
    Route::post('/run',            [InstallController::class, 'run'])->name('run');
    Route::get('/progress/{key}',  [InstallController::class, 'progress'])->name('progress');
    Route::get('/stream/{key}',    [InstallController::class, 'stream'])->name('stream');
    Route::get('/manual',          [InstallController::class, 'manual'])->name('manual');
    Route::post('/manual',         [InstallController::class, 'runManual'])->name('manual.run');
    Route::get('/done',            [InstallController::class, 'done'])->name('done');
});

Route::prefix('super')->name('super.')->middleware(SetupComplete::class)->group(function () {

    // Setup (repair mode only — initial setup now handled by /install)
    Route::get('/setup', [SetupController::class, 'show'])->name('setup');
    Route::post('/setup', [SetupController::class, 'run'])->name('setup.run');

    // Auth
    
    Route::get('/login', [SuperAuthController::class, 'showLogin'])->name('login');
    Route::get('/auth/callback', [SuperAuthController::class, 'callback'])->name('auth.callback');
    Route::post('/logout', [SuperAuthController::class, 'logout'])->name('logout');
    Route::get('/session-check', [SuperAuthController::class, 'sessionCheck'])->name('session.check')->middleware(RequireSuperAuth::class);
    Route::middleware(RequireSuperAuth::class)->group(function () {
        // Wizard — Mailcow + Nextcloud setup (not gated by WizardComplete)
        Route::get('/wizard',                [WizardController::class, 'welcome'])->name('wizard');
        Route::get('/wizard/server-type',    [WizardController::class, 'serverType'])->name('wizard.server-type');
        Route::get('/wizard/single',         [WizardController::class, 'singleServer'])->name('wizard.single');
        Route::post('/wizard/single',        [WizardController::class, 'installSingle'])->name('wizard.single.install');
        Route::get('/wizard/multi',          [WizardController::class, 'multiServer'])->name('wizard.multi');
        Route::post('/wizard/multi',         [WizardController::class, 'installMulti'])->name('wizard.multi.install');
        Route::get('/wizard/done',           [WizardController::class, 'done'])->name('wizard.done');
        Route::post('/wizard/skip',          [WizardController::class, 'skip'])->name('wizard.skip');
        Route::post('/wizard/reset',         [WizardController::class, 'reset'])->name('wizard.reset');

        // All other super admin routes require the wizard to have been completed
        Route::middleware(WizardComplete::class)->group(function () {
            Route::get('/realms', [SuperRealmController::class, 'index'])->name('realms');
            Route::get('/realms/create', [SuperRealmController::class, 'create'])->name('realms.create');
            Route::post('/realms', [SuperRealmController::class, 'store'])->name('realms.store');
            Route::get('/realms/{realm}/edit', [SuperRealmController::class, 'edit'])->name('realms.edit');
            Route::put('/realms/{realm}', [SuperRealmController::class, 'update'])->name('realms.update');
            Route::post('/realms/{realm}/toggle', [SuperRealmController::class, 'toggle'])->name('realms.toggle');
            Route::put('/realms/{realm}/limits', [SuperRealmController::class, 'updateLimits'])->name('realms.limits');
            Route::get('/realms/{realm}/mailcow-settings', [SuperRealmController::class, 'mailcowSettings'])->name('realms.mailcow-settings');
            Route::put('/realms/{realm}/mailcow-limits', [SuperRealmController::class, 'updateMailcowLimits'])->name('realms.mailcow-limits');
            Route::delete('/realms/{realm}/mailcow', [SuperRealmController::class, 'removeMailcow'])->name('realms.remove-mailcow');
            Route::get('/realms/{realm}/nextcloud-settings', [SuperRealmController::class, 'nextcloudSettings'])->name('realms.nextcloud-settings');
            Route::put('/realms/{realm}/nextcloud', [SuperRealmController::class, 'updateNextcloud'])->name('realms.nextcloud-update');
            Route::delete('/realms/{realm}/nextcloud', [SuperRealmController::class, 'removeNextcloud'])->name('realms.nextcloud-remove');
            Route::delete('/realms/{realm}', [SuperRealmController::class, 'destroy'])->name('realms.destroy');
            Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs');
            Route::get('/settings', [SettingsController::class, 'show'])->name('settings');
            Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
        });
    });
});
