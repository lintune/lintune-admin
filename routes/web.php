<?php

use App\Http\Controllers\Super\SettingsController;
use App\Http\Controllers\Super\AuditLogController;
use App\Http\Controllers\Super\SetupController;
use App\Http\Controllers\Super\SuperAuthController;
use App\Http\Controllers\Super\SuperRealmController;
use App\Http\Middleware\RequireSuperAuth;
use App\Http\Middleware\SetupComplete;
use Illuminate\Support\Facades\Route;


Route::get('/', fn() => redirect()->route('super.login'));

Route::prefix('super')->name('super.')->middleware(SetupComplete::class)->group(function () {

    // Setup (only accessible before SETUP_COMPLETE=true)
    
    Route::get('/setup', [SetupController::class, 'show'])->name('setup');
    Route::post('/setup', [SetupController::class, 'run'])->name('setup.run');

    // Auth
    
    Route::get('/login', [SuperAuthController::class, 'showLogin'])->name('login');
    Route::get('/auth/callback', [SuperAuthController::class, 'callback'])->name('auth.callback');
    Route::post('/logout', [SuperAuthController::class, 'logout'])->name('logout');
    Route::get('/session-check', [SuperAuthController::class, 'sessionCheck'])->name('session.check')->middleware(RequireSuperAuth::class);
    Route::middleware(RequireSuperAuth::class)->group(function () {
        Route::get('/realms', [SuperRealmController::class, 'index'])->name('realms');
        Route::get('/realms/create', [SuperRealmController::class, 'create'])->name('realms.create');
        Route::post('/realms', [SuperRealmController::class, 'store'])->name('realms.store');
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
