<?php

use App\Http\Controllers\Super\SuperAuthController;
use App\Http\Controllers\Super\SuperRealmController;
use App\Http\Middleware\RequireSuperAuth;
use Illuminate\Support\Facades\Route;

Route::get('/', fn() => redirect()->route('super.login'));

Route::prefix('super')->name('super.')->group(function () {
    Route::get('/login', [SuperAuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [SuperAuthController::class, 'login'])->name('login.submit');
    Route::post('/logout', [SuperAuthController::class, 'logout'])->name('logout');
    Route::get('/session-check', [SuperAuthController::class, 'sessionCheck'])->name('session.check')->middleware(RequireSuperAuth::class);

    Route::middleware(RequireSuperAuth::class)->group(function () {
        Route::get('/realms', [SuperRealmController::class, 'index'])->name('realms');
        Route::get('/realms/create', [SuperRealmController::class, 'create'])->name('realms.create');
        Route::post('/realms', [SuperRealmController::class, 'store'])->name('realms.store');
        Route::post('/realms/{realm}/toggle', [SuperRealmController::class, 'toggle'])->name('realms.toggle');
        Route::get('/realms/{realm}/check-mailcow', [SuperRealmController::class, 'checkMailcow'])->name('realms.check-mailcow');
        Route::post('/realms/{realm}/toggle-mailcow', [SuperRealmController::class, 'toggleMailcow'])->name('realms.toggle-mailcow');
        Route::delete('/realms/{realm}', [SuperRealmController::class, 'destroy'])->name('realms.destroy');
    });
});
