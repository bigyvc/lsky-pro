<?php

use App\Http\Controllers\GalleryController;
use App\Http\Controllers\GalleryVideoController;
use App\Http\Middleware\GalleryAccess;
use App\Services\GallerySettings;
use Illuminate\Support\Facades\Route;

// Register before generic '/' without querying a potentially unmigrated database.
Route::domain(GallerySettings::routeHost())->middleware(GalleryAccess::class)
    ->withoutMiddleware([
        \App\Http\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \App\Http\Middleware\VerifyCsrfToken::class,
    ])->group(function () {
    Route::get('/',[GalleryController::class,'index']);
    Route::get('/sets/{key}',[GalleryController::class,'index'])->where('key','[a-f0-9]{32}');
    Route::get('/videos',[GalleryVideoController::class,'index']);
    Route::get('/videos/{key}',[GalleryVideoController::class,'index'])->where('key','[A-Za-z0-9]{1,64}');
    Route::match(['GET','HEAD'],'/gallery-video-media/{key}/{kind}',[GalleryVideoController::class,'media'])
        ->where('key','[A-Za-z0-9]{1,64}')->where('kind','poster|original');
    Route::prefix('gallery-api')->middleware('throttle:120,1')->group(function () {
        Route::get('videos',[GalleryVideoController::class,'listing']);
        Route::get('sets',[GalleryController::class,'listing']);
        Route::get('sets/{key}',[GalleryController::class,'detail'])->where('key','[a-f0-9]{32}');
    });
    Route::match(['GET','HEAD'],'/gallery-media/{post}/{key}/{kind}',[GalleryController::class,'media'])
        ->where('post','[a-f0-9]{32}')->where('key','[A-Za-z0-9]+')->where('kind','thumbnail|original');
    Route::any('{unmatched}',fn()=>abort(404))->where('unmatched','.*');
});
