<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\ReportGenerationException;
use App\Services\Reports\CategoryReportGenerator;
use App\Services\Reports\ReportProcessTracker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Throwable;

final class GenerateCategoryReportCommand extends Command
{
    protected $signature = 'report:generate
                            {category_id : Identifier of the product category}';

    protected $description = 'Generate CSV price reports for a product category';

    public function handle(
        CategoryReportGenerator $generator,
        ReportProcessTracker $tracker,
    ): int {
        $pid = getmypid();

        if ($pid === false) {
            $this->error('Unable to determine the current process identifier.');

            return self::FAILURE;
        }

        $categoryId = filter_var(
            $this->argument('category_id'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        if ($categoryId === false) {
            $tracker->recordFailure($pid);
            $this->error('The category_id argument must be a positive integer.');

            return self::FAILURE;
        }

        $startedAt = hrtime(true);
        $startedOn = Date::now('UTC')->toImmutable();

        try {
            if ($this->callSilent('report:recover') !== self::SUCCESS) {
                $this->error('Unable to recover interrupted reports.');

                return self::FAILURE;
            }

            $result = $generator->generate($categoryId, $pid);
        } catch (ReportGenerationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            try {
                $tracker->recordFailure($pid, (hrtime(true) - $startedAt) / 1_000_000_000, $startedOn);
            } catch (Throwable $trackingException) {
                Log::warning('Unable to record a failed report process.', ['exception' => $trackingException]);
            }

            Log::error('Unable to prepare a category report.', [
                'category_id' => $categoryId,
                'exception' => $exception,
            ]);
            $this->error('Unable to prepare the category report.');

            return self::FAILURE;
        }

        if (! $result->successful) {
            $this->error($result->errorMessage ?? 'The report could not be generated.');

            return self::FAILURE;
        }

        $this->info('Reports generated successfully.');
        $this->line(sprintf(
            'Period: %s — %s',
            $result->dateFrom->toDateString(),
            $result->dateTo->toDateString(),
        ));
        $this->line("Manufacturers: {$result->manufacturerCount}");
        $this->line('Files: '.count($result->paths));

        foreach ($result->paths as $path) {
            $this->line(" - {$path}");
        }

        $elapsed = (hrtime(true) - $startedAt) / 1_000_000_000;
        $this->line(sprintf('Total execution time: %.3f s', $elapsed));

        return self::SUCCESS;
    }
}
