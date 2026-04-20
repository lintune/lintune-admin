<?php

use App\Http\Controllers\Super\SettingsController;
use App\Http\Controllers\Super\AuditLogController;
use App\Http\Controllers\Super\SetupController;
use App\Http\Controllers\Super\SuperAuthController;
use App\Http\Controllers\Super\SuperRealmController;
use App\Http\Middleware\RequireSuperAuth;
use App\Http\Middleware\SetupComplete;
use Illuminate\Support\Facades\Route;

// amazonq-ignore-next-line
Route::get('/', fn() => redirect()->route('super.login'));

Route::prefix('super')->name('super.')->middleware(SetupComplete::class)->group(function () {

    // Setup (only accessible before SETUP_COMPLETE=true)
    // amazonq-ignore-next-line
    Route::get('/setup', [SetupController::class, 'show'])->name('setup');
    // amazonq-ignore-next-line
    Route::post('/setup', [SetupController::class, 'run'])->name('setup.run');

    // Auth
    // amazonq-ignore-next-line
    Route::get('/login', [SuperAuthController::class, 'showLogin'])->name('login');
    // amazonq-ignore-next-line
    Route::get('/auth/callback', [SuperAuthController::class, 'callback'])->name('auth.callback');
    // amazonq-ignore-next-line
    Route::post('/logout', [SuperAuthController::class, 'logout'])->name('logout');
    // amazonq-ignore-next-line
    Route::get('/session-check', [SuperAuthController::class, 'sessionCheck'])->name('session.check')->middleware(RequireSuperAuth::class);

    Route::middleware(RequireSuperAuth::class)->group(function () {
        // amazonq-ignore-next-line
        Route::get('/realms', [SuperRealmController::class, 'index'])->name('realms');
        // amazonq-ignore-next-line
        Route::get('/realms/create', [SuperRealmController::class, 'create'])->name('realms.create');
        // amazonq-ignore-next-line
        Route::post('/realms', [SuperRealmController::class, 'store'])->name('realms.store');
        // amazonq-ignore-next-line
        Route::post('/realms/{realm}/toggle', [SuperRealmController::class, 'toggle'])->name('realms.toggle');
        // amazonq-ignore-next-line
        Route::get('/realms/{realm}/mailcow-settings', [SuperRealmController::class, 'mailcowSettings'])->name('realms.mailcow-settings');
        // amazonq-ignore-next-line
        Route::put('/realms/{realm}/mailcow-limits', [SuperRealmController::class, 'updateMailcowLimits'])->name('realms.mailcow-limits');
        // amazonq-ignore-next-line
        Route::delete('/realms/{realm}/mailcow', [SuperRealmController::class, 'removeMailcow'])->name('realms.remove-mailcow');
        // amazonq-ignore-next-line
        Route::delete('/realms/{realm}', [SuperRealmController::class, 'destroy'])->name('realms.destroy');
        // amazonq-ignore-next-line
        Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs');
        // amazonq-ignore-next-line
        Route::get('/settings', [SettingsController::class, 'show'])->name('settings');
        // amazonq-ignore-next-line
        Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
    });
});
