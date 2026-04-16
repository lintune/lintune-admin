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

    Route::middleware(RequireSuperAuth::class)->group(function () {
        Route::get('/realms', [SuperRealmController::class, 'index'])->name('realms');
        Route::get('/realms/create', [SuperRealmController::class, 'create'])->name('realms.create');
        Route::post('/realms', [SuperRealmController::class, 'store'])->name('realms.store');
    });
});
