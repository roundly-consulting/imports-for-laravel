<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Workbench\App\Models\User;

Route::get('/', static fn () => response()->json([
    'workbench' => 'imports-for-laravel',
    'sign_in' => url('/login-as/1').' (admin of team 1) · /login-as/2 (member of team 1)',
    'api' => url('/api/imports/importers'),
]));

// Local only: sign in as a seeded user without a password.
if (app()->isLocal()) {
    Route::middleware('web')->get('/login-as/{user}', static function (User $user) {
        Auth::login($user);

        return redirect('/api/imports/importers');
    });
}
