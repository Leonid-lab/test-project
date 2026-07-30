<?php

declare(strict_types=1);

use App\Http\Controllers\ReportProcessController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/report-processes');

Route::get('/report-processes', [ReportProcessController::class, 'index'])
    ->name('report-processes.index');
Route::get('/report-processes/{reportProcess}/download', [ReportProcessController::class, 'download'])
    ->name('report-processes.download');
