<?php

use BhavneeshGoyal\LaravelSmartBackup\Http\Controllers\BackupController;
use Illuminate\Support\Facades\Route;

Route::get('/backups', [BackupController::class, 'index'])->name('backups.index');
Route::get('/settings', [BackupController::class, 'settings'])->name('settings');
Route::post('/settings', [BackupController::class, 'updateSettings'])->name('settings.update');
Route::post('/backups/run', [BackupController::class, 'run'])->name('backups.run');
Route::post('/backups/restore', [BackupController::class, 'restore'])->name('backups.restore');
Route::delete('/backups/{run}', [BackupController::class, 'destroy'])->name('backups.destroy');
