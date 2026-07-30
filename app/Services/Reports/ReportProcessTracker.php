<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\ProcessStatusId;
use App\Models\ReportProcess;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ReportProcessTracker
{
    /**
     * Сохраняет начальный статус до формирования файла.
     */
    public function start(int $pid): ReportProcess
    {
        return ReportProcess::query()->create([
            'rp_pid' => $pid,
            'rp_start_datetime' => Date::now(),
            'ps_id' => ProcessStatusId::Running,
        ]);
    }

    public function complete(ReportProcess $process, float $seconds, string $path): void
    {
        $process->update([
            'rp_exec_time' => $this->formatDuration($seconds),
            'rp_file_save_path' => $path,
            'ps_id' => ProcessStatusId::Completed,
        ]);
    }

    public function fail(ReportProcess $process, float $seconds): void
    {
        $process->update([
            'rp_exec_time' => $this->formatDuration($seconds),
            'rp_file_save_path' => null,
            'ps_id' => ProcessStatusId::Failed,
        ]);
    }

    public function recordExpectedFailure(int $pid): void
    {
        $process = $this->start($pid);
        $this->fail($process, 0.0);
    }

    public function failWithoutHiding(
        ReportProcess $process,
        float $seconds,
        Throwable $original,
    ): void {
        // Ошибка журнала не должна скрывать исходную ошибку формирования отчёта.
        try {
            $this->fail($process, $seconds);
        } catch (Throwable $trackingException) {
            Log::warning('Unable to mark a report process as failed.', [
                'process_id' => $process->rp_id,
                'original_exception_class' => $original::class,
                'exception' => $trackingException,
            ]);
        }
    }

    private function formatDuration(float $seconds): string
    {
        return number_format($seconds, 3, '.', '');
    }
}
