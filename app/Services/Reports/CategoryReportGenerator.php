<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Data\CategoryReportResult;
use App\Exceptions\ReportGenerationException;
use App\Repositories\PriceReportRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Throwable;

final class CategoryReportGenerator
{
    public function __construct(
        private readonly PriceReportRepository $repository,
        private readonly CsvReportWriter $writer,
        private readonly ReportProcessTracker $tracker,
    ) {}

    /**
     * Создаёт отдельный CSV и запись журнала для каждого производителя.
     */
    public function generate(int $categoryId, int $pid): CategoryReportResult
    {
        $dateTo = $this->today();
        $dateFrom = $dateTo->subDays($this->periodDays() - 1);

        if (! $this->repository->categoryHasProducts($categoryId)) {
            $this->tracker->recordExpectedFailure($pid);

            return new CategoryReportResult(
                successful: false,
                dateFrom: $dateFrom,
                dateTo: $dateTo,
                manufacturerCount: 0,
                paths: [],
                errorMessage: "Category {$categoryId} has no products.",
            );
        }

        $manufacturers = $this->repository->reportableManufacturers(
            $categoryId,
            $dateFrom,
            $dateTo,
        );

        if ($manufacturers === []) {
            $this->tracker->recordExpectedFailure($pid);

            return new CategoryReportResult(
                successful: false,
                dateFrom: $dateFrom,
                dateTo: $dateTo,
                manufacturerCount: 0,
                paths: [],
                errorMessage: 'No prices were found for the selected seven-day period.',
            );
        }

        $paths = [];
        $generatedAt = Date::now()->toImmutable();

        // Независимый статус каждого файла позволяет видеть частичный результат.
        foreach ($manufacturers as $manufacturer) {
            $process = $this->tracker->start($pid);
            $startedAt = hrtime(true);
            $path = null;

            try {
                $path = $this->writer->write(
                    $manufacturer,
                    $categoryId,
                    $generatedAt,
                    $this->repository->rows(
                        $categoryId,
                        $manufacturer->id,
                        $dateFrom,
                        $dateTo,
                    ),
                    $process->rp_id,
                );
                $this->tracker->complete($process, $this->elapsedSeconds($startedAt), $path);
                $paths[] = $path;
            } catch (Throwable $exception) {
                if ($path !== null) {
                    try {
                        $this->writer->delete($path);
                    } catch (Throwable $cleanupException) {
                        Log::warning('Unable to remove a failed report file.', [
                            'process_id' => $process->rp_id,
                            'path' => $path,
                            'exception' => $cleanupException,
                        ]);
                    }
                }

                $this->tracker->failWithoutHiding(
                    $process,
                    $this->elapsedSeconds($startedAt),
                    $exception,
                );

                Log::error('Category report generation failed.', [
                    'category_id' => $categoryId,
                    'manufacturer_id' => $manufacturer->id,
                    'process_id' => $process->rp_id,
                    'exception' => $exception,
                ]);

                throw new ReportGenerationException(
                    "Report generation failed for manufacturer {$manufacturer->id}.",
                    0,
                    $exception,
                );
            }
        }

        return new CategoryReportResult(
            successful: true,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            manufacturerCount: count($manufacturers),
            paths: $paths,
        );
    }

    private function today(): CarbonImmutable
    {
        return Date::now(config('app.timezone'))->toImmutable()->startOfDay();
    }

    private function periodDays(): int
    {
        $days = config('reports.period_days');

        if (! is_int($days) || $days < 1) {
            throw new ReportGenerationException('The report period is not configured correctly.');
        }

        return $days;
    }

    private function elapsedSeconds(int $startedAt): float
    {
        return (hrtime(true) - $startedAt) / 1_000_000_000;
    }
}
