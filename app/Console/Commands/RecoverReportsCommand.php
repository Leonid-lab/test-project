<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProcessStatusId;
use App\Models\ReportProcess;
use App\Services\Reports\CsvReportWriter;
use App\Services\Reports\ReportProcessTracker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Throwable;

final class RecoverReportsCommand extends Command
{
    protected $signature = 'report:recover';

    protected $description = 'Remove files from interrupted reports and mark their processes as failed';

    public function handle(ReportProcessTracker $tracker, CsvReportWriter $writer): int
    {
        $recovered = 0;

        foreach (ReportProcess::query()->where('ps_id', ProcessStatusId::Running->value)->lazyById(100, 'rp_id') as $process) {
            if (! $tracker->acquire($process)) {
                continue;
            }

            try {
                $process->refresh();

                if ($process->ps_id !== ProcessStatusId::Running) {
                    continue;
                }

                $writer->discard($process->rp_id);
                $tracker->fail($process, $process->rp_start_datetime->diffInSeconds(Date::now('UTC'), true));
                $recovered++;
            } catch (Throwable $exception) {
                report($exception);
                $this->error("Unable to recover report process {$process->rp_id}.");

                return self::FAILURE;
            } finally {
                $tracker->release($process);
            }
        }

        $this->info("Recovered reports: {$recovered}");

        return self::SUCCESS;
    }
}
