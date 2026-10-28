<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ReportProcess;
use App\Services\Reports\ReportFileDownloadService;
use App\Services\Reports\ReportProcessListService;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Принимает HTTP-запросы для журнала процессов.
 */
final class ReportProcessController extends Controller
{
    public function __construct(
        private readonly ReportProcessListService $listService,
        private readonly ReportFileDownloadService $downloadService,
    ) {}

    public function index(): View
    {
        return view('report-processes.index', $this->listService->viewData());
    }

    public function download(ReportProcess $reportProcess): StreamedResponse
    {
        return $this->downloadService->download($reportProcess);
    }
}
