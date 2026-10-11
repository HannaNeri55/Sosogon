<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/storage/{path}', function (string $path) {
    abort_if(str_contains($path, '..'), 404);

    $fullPath = storage_path('app/public/'.$path);

    abort_unless(is_file($fullPath), 404);

    return response()->file($fullPath);
})->where('path', '.*');
