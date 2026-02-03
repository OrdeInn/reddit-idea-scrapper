<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IdeaController;
use App\Http\Controllers\ScanController;
use App\Http\Controllers\SubredditController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// Dashboard
Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

// Subreddits
Route::post('/subreddits', [SubredditController::class, 'store'])->name('subreddit.store');
Route::get('/subreddits/{subreddit}', [SubredditController::class, 'show'])->name('subreddit.show');
Route::delete('/subreddits/{subreddit}', [SubredditController::class, 'destroy'])->name('subreddit.destroy');

// Scans
Route::post('/subreddits/{subreddit}/scan', [ScanController::class, 'start'])->name('scan.start');
Route::get('/scans/{scan}/status', [ScanController::class, 'status'])->name('scan.status');
Route::post('/scans/{scan}/cancel', [ScanController::class, 'cancel'])->name('scan.cancel');
Route::post('/scans/{scan}/retry', [ScanController::class, 'retry'])->name('scan.retry');

// Ideas
Route::get('/subreddits/{subreddit}/ideas', [IdeaController::class, 'index'])->name('ideas.index');
Route::get('/starred', [IdeaController::class, 'starred'])->name('ideas.starred');
Route::get('/api/starred', [IdeaController::class, 'starredList'])->name('api.ideas.starred');
Route::get('/ideas/{idea}', [IdeaController::class, 'show'])->name('ideas.show');
Route::post('/ideas/{idea}/star', [IdeaController::class, 'toggleStar'])->name('ideas.star');
