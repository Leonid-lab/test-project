<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\ProcessStatusId;
use App\Models\ReportProcess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ReportProcessTracker
{
    /**
     * Сохраняет начальный статус до формирования файла.
     */
    public function start(int $pid, ?CarbonImmutable $startedAt = null): ReportProcess
    {
        return DB::transaction(function () use ($pid, $startedAt): ReportProcess {
            $process = ReportProcess::query()->create([
                'rp_pid' => $pid,
                'rp_start_datetime' => $startedAt?->utc() ?? Date::now('UTC'),
                'ps_id' => ProcessStatusId::Running,
            ]);
            DB::selectOne('SELECT pg_advisory_lock(?)', [$process->rp_id], false);

            return $process;
        });
    }

    public function acquire(ReportProcess $process): bool
    {
        // PostgreSQL разрешает повторный захват своей блокировки; такой отчёт ещё активен.
        return DB::selectOne(
            <<<'SQL'
                SELECT pg_try_advisory_lock(?) AS acquired
                WHERE NOT EXISTS (
                    SELECT 1 FROM pg_locks
                    WHERE locktype = 'advisory'
                      AND pid = pg_backend_pid()
                      AND objsubid = 1
                      AND ((classid::bigint << 32) | objid::bigint) = ?
                )
                SQL,
            [$process->rp_id, $process->rp_id],
            false,
        )?->acquired === true;
    }

    public function release(ReportProcess $process): void
    {
        try {
            DB::selectOne('SELECT pg_advisory_unlock(?)', [$process->rp_id], false);
        } catch (Throwable $exception) {
            Log::warning('Unable to release a report process lock.', [
                'process_id' => $process->rp_id,
                'exception' => $exception,
            ]);
        }
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

    public function recordFailure(int $pid, float $seconds = 0.0, ?CarbonImmutable $startedAt = null): void
    {
        $process = $this->start($pid, $startedAt);

        try {
            $this->fail($process, $seconds);
        } finally {
            $this->release($process);
        }
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
