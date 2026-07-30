<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\ReportProcess;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Подготавливает данные журнала процессов для Blade-шаблона.
 */
final class ReportProcessListService
{
    private const ITEMS_PER_PAGE = 50;

    public function __construct(
        private readonly ReportFileDownloadService $downloadService,
    ) {}

    /**
     * @return array{
     *     processes: LengthAwarePaginator<int, ReportProcess>,
     *     downloadableIds: array<int, true>
     * }
     */
    public function viewData(): array
    {
        $processes = ReportProcess::query()
            ->with('status')
            ->orderByDesc('rp_start_datetime')
            ->orderByDesc('rp_id')
            ->paginate(self::ITEMS_PER_PAGE);
        $downloadableIds = [];

        // Шаблон получает готовую карту и не повторяет правила работы с файлами.
        foreach ($processes as $process) {
            if ($this->downloadService->isDownloadable($process)) {
                $downloadableIds[$process->rp_id] = true;
            }
        }

        return [
            'processes' => $processes,
            'downloadableIds' => $downloadableIds,
        ];
    }
}
