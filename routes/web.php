<?php

use App\Http\Controllers\GaidController;
use Illuminate\Support\Facades\Route;

Route::get('/', [GaidController::class, 'index'])->name('gaid.index');
Route::get('/ndpc', [GaidController::class, 'ndpc'])->name('gaid.ndpc');
Route::get('/ndpc/data', [GaidController::class, 'ndpcData'])->name('gaid.ndpc.data');

Route::post('/assess', [GaidController::class, 'assess'])->name('gaid.assess');
Route::post('/upload-documents', [GaidController::class, 'uploadDocuments'])->name('gaid.upload-documents');
Route::post('/generate-car', [GaidController::class, 'generateCar'])->name('gaid.generate-car');
Route::post('/submit', [GaidController::class, 'submit'])->name('gaid.submit');

Route::get('/submission/{referenceCode}', [GaidController::class, 'show'])->name('gaid.show');
