<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TrackerController;

/*
|--------------------------------------------------------------------------
| Tracker Routes
|--------------------------------------------------------------------------
|
| Here is Johnny.
|
*/

Route::get('/{id}/announce', [TrackerController::class, 'track'])->name('announce');
Route::get('/{id}/scrape', [TrackerController::class, 'scrape'])->name('scrape');