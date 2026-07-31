<?php

use App\Http\Controllers\Api\V1\ApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (token-authed v1, read-only)
|--------------------------------------------------------------------------
|
| auth:sanctum resolves the user from the bearer token; api.tenant then
| activates the tenant the token is bound to, so every query below is
| isolated to that one tenant via the BelongsToTenant global scope.
|
*/

Route::middleware(['auth:sanctum', 'api.tenant'])
    ->prefix('v1')
    ->group(function () {
        Route::get('/employees', [ApiController::class, 'employees']);
        Route::get('/leave-requests', [ApiController::class, 'leaveRequests']);
        Route::get('/payslips', [ApiController::class, 'payslips']);
    });
