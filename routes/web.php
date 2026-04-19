<?php

use App\Http\Controllers\Super\SuperAuthController;
use App\Http\Controllers\Super\SuperRealmController;
use App\Http\Middleware\RequireSuperAuth;
use Illuminate\Support\Facades\Route;

// amazonq-ignore-next-line
Route::get('/', fn() => redirect()->route('super.login'));

Route::prefix('super')->name('super.')->group(function () {
    // amazonq-ignore-next-line
    Route::get('/login', [SuperAuthController::class, 'showLogin'])->name('login');
    // amazonq-ignore-next-line
    Route::post('/login', [SuperAuthController::class, 'login'])->name('login.submit');
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
        Route::get('/realms/{realm}/check-mailcow', [SuperRealmController::class, 'checkMailcow'])->name('realms.check-mailcow');
        // amazonq-ignore-next-line
        Route::post('/realms/{realm}/toggle-mailcow', [SuperRealmController::class, 'toggleMailcow'])->name('realms.toggle-mailcow');
        // amazonq-ignore-next-line
        Route::delete('/realms/{realm}', [SuperRealmController::class, 'destroy'])->name('realms.destroy');
    });
});
