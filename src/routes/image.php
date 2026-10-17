<?php

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Route;
use App\Enums\GroupConfigKey;
use App\Enums\ConfigKey;

$extensions = array_merge(config('convention.group.accepted_file_suffixes'), \App\Services\VideoMetadata::EXTENSIONS);
Route::middleware('cache.headers:public;max_age=2628000;etag')->group(function () use ($extensions) {
    $extensions = array_merge(array_map('strtoupper', $extensions), array_map('strtolower', $extensions));
    // The key identifies the record; filename is a readable label, never a storage path.
    // Do not require the current stored name: archive collision handling may change it.
    Route::match(['GET','HEAD'], '{key}/{filename}.{extension}', [
        Controller::class, 'output',
    ])->where('key', '[A-Za-z0-9]+')->where('filename', '[^/]+')
      ->where('extension', implode('|', $extensions))->name('file.named');
    Route::any('{key}.{extension}', [
        Controller::class, 'output',
    ])->where('extension', implode('|', $extensions));
});
