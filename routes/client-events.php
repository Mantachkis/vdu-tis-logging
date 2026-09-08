<?php

use Illuminate\Support\Facades\Route;
use Vdu\TisLogging\Http\Controllers\ClientEventController;

/*
|--------------------------------------------------------------------------
| Klientinės pusės įvykių endpoint'as
|--------------------------------------------------------------------------
|
| Registruojamas TIK jei config('audit.client_events.enabled') = true.
| Middleware ir kelias konfigūruojami per config/audit.php.
|
*/

Route::post(
    config('audit.client_events.route', '/audit/client-event'),
    ClientEventController::class
)->middleware(config('audit.client_events.middleware', ['web', 'throttle:60,1']))
 ->name('audit.client-event');
