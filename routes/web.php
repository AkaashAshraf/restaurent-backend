<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 * Uploaded images (logo, app icon, banners...) are served from /storage. When
 * `php artisan storage:link` has been run the web server hands those files out
 * directly and this route is never reached; when the link is missing (a
 * common slip on a fresh server) this keeps the images working instead of 404.
 */
Route::get('/storage/{path}', function (string $path) {
    $disk = Illuminate\Support\Facades\Storage::disk('public');

    // Only plain relative paths to files that exist; Flysystem rejects ".." too.
    abort_if($path === '' || str_contains($path, '..') || ! $disk->exists($path), 404);

    return $disk->response($path, null, ['Cache-Control' => 'public, max-age=86400']);
})->where('path', '.*');
