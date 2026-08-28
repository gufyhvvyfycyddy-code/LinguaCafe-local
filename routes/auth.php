<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use Illuminate\Support\Facades\Route;

// Public login/setup/registration are owned by UserController in routes/web.php.
// Password reset and email verification remain intentionally unexposed until a
// deployment has an accepted outbound-mail/recovery flow.

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
                ->middleware('auth')
                ->name('logout');
