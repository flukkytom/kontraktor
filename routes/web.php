<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

// Local-only: log in as a seeded user for design previews / screenshots.
if (app()->isLocal()) {
    Route::get('/dev/login/{email}', function (string $email) {
        Auth::login(User::where('email', $email)->firstOrFail());

        return redirect('/admin');
    });
}
