<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\CollectionController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\LoanController;
use App\Http\Controllers\Api\ReceiptController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => ['status' => 'ok', 'app' => 'D Money API']);
Route::post('/login', [AuthController::class, 'login']);
Route::get('/dashboard', DashboardController::class);
Route::apiResource('users', UserController::class)->only(['index', 'store', 'update', 'destroy']);
Route::apiResource('employees', EmployeeController::class)->only(['index', 'store']);
Route::apiResource('clients', ClientController::class);
Route::apiResource('loans', LoanController::class);
Route::apiResource('collections', CollectionController::class)->only(['index', 'store', 'show']);
Route::get('/clients/{client}/ledger', [ReceiptController::class, 'ledger']);
Route::post('/loans/{loan}/receipts', [ReceiptController::class, 'store']);
Route::get('/reports/{type}', [ReportController::class, 'show']);
