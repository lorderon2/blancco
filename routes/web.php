<?php

use App\Http\Controllers\AttemptController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\InstallationJobController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\EnsureActiveUser;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/zapomniane-haslo', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/zapomniane-haslo', [PasswordResetController::class, 'email'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-hasla/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');
    Route::post('/reset-hasla', [PasswordResetController::class, 'update'])->middleware('throttle:5,1')->name('password.update');
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:20,1');
});
Route::middleware(['auth', EnsureActiveUser::class])->group(function () {
    Route::get('/', fn () => redirect()->route('jobs.index'));
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    Route::get('/zlecenia', [InstallationJobController::class, 'index'])->name('jobs.index');
    Route::get('/zlecenia/nowe', [InstallationJobController::class, 'create'])->name('jobs.create');
    Route::post('/zlecenia/seriale-bc', [InstallationJobController::class, 'bcSerials'])->middleware('throttle:20,1')->name('jobs.bc-serials');
    Route::post('/zlecenia', [InstallationJobController::class, 'store'])->name('jobs.store');
    Route::get('/zlecenia/{job}', [InstallationJobController::class, 'show'])->name('jobs.show');
    Route::post('/zlecenia/{job}/anuluj', [InstallationJobController::class, 'cancel'])->name('jobs.cancel');
    Route::post('/proby/{attempt}/rozstrzygnij', [AttemptController::class, 'resolve'])->name('attempts.resolve');
    Route::get('/urzadzenia/{device}/raporty', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/urzadzenia/{device}/raporty/{uuid}/certyfikat', [ReportController::class, 'certificate'])->name('reports.certificate');
    Route::get('/raporty/{uuid}/pdf', [ReportController::class, 'pdf'])->name('reports.pdf');
    Route::get('/uzytkownicy', [UserController::class, 'index'])->name('users.index');
    Route::post('/uzytkownicy', [UserController::class, 'store'])->name('users.store');
});
