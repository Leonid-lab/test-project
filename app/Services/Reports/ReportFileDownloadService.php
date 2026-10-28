<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\ProcessStatusId;
use App\Models\ReportProcess;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Проверяет доступность отчёта и передаёт разрешённый файл пользователю.
 */
final class ReportFileDownloadService
{
    public function download(ReportProcess $process): StreamedResponse
    {
        abort_unless($this->isDownloadable($process), 404);

        $path = $process->rp_file_save_path;
        abort_unless(is_string($path), 404);

        return Storage::disk($this->diskName())->download($path, basename($path));
    }

    public function isDownloadable(ReportProcess $process): bool
    {
        if (
            $process->ps_id !== ProcessStatusId::Completed
            || ! is_string($process->rp_file_save_path)
            || ! $this->isSafeReportPath($process->rp_file_save_path)
        ) {
            return false;
        }

        return Storage::disk($this->diskName())->exists($process->rp_file_save_path);
    }

    /**
     * Разрешены только имена, которые создаёт CsvReportWriter в закрытом каталоге.
     * Значение из базы данных не считается доверенным путём к файлу.
     */
    private function isSafeReportPath(string $path): bool
    {
        $directory = config('reports.directory');

        if (! is_string($directory) || $directory === '') {
            return false;
        }

        $directory = trim($directory, '/');
        $pattern = '#^'.preg_quote($directory, '#')
            .'/(?:[1-9]\d*/)?report_\d+_\d+_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.csv$#D';

        return ! str_contains($path, '\\')
            && ! str_contains($path, '..')
            && preg_match($pattern, $path) === 1;
    }

    private function diskName(): string
    {
        $diskName = config('reports.disk');
        abort_unless(is_string($diskName) && $diskName !== '', 404);

        return $diskName;
    }
}
