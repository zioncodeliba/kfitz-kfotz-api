<?php

use Illuminate\Support\Facades\Route;
// use App\Http\Controllers\Api\UserController;

Route::get('/', function () {
    return view('welcome');
});
// Route::get('/users', [UserController::class, 'index']);