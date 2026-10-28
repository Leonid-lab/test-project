<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ProcessStatusId;
use App\Models\ReportProcess;
use App\Services\Reports\ReportProcessTracker;
use Carbon\CarbonImmutable;
use Database\Seeders\ProcessStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ReportProcessHttpTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = ProcessStatusSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config()->set('reports.disk', 'local');
        config()->set('reports.directory', 'reports');
    }

    public function test_index_displays_sorted_processes_and_only_valid_download_links(): void
    {
        $completedPath = 'reports/report_1_10_2026-07-29_10-42-15.csv';
        Storage::disk('local')->put($completedPath, 'csv');

        $older = $this->process(
            pid: 100,
            status: ProcessStatusId::Running,
            startedAt: '2026-07-28 10:00:00',
        );
        $failed = $this->process(
            pid: 200,
            status: ProcessStatusId::Failed,
            startedAt: '2026-07-29 09:00:00',
        );
        $completed = $this->process(
            pid: 300,
            status: ProcessStatusId::Completed,
            startedAt: '2026-07-29 10:00:00',
            path: $completedPath,
        );

        $response = $this->get(route('report-processes.index'));

        $response->assertOk()
            ->assertSee('Контроль выполнения процессов')
            ->assertSeeInOrder(['300', '200', '100'])
            ->assertSee('process-failed', false)
            ->assertSee(route('report-processes.download', $completed), false)
            ->assertDontSee(route('report-processes.download', $failed), false)
            ->assertDontSee(route('report-processes.download', $older), false);
    }

    public function test_download_uses_the_database_path_and_rejects_unavailable_files(): void
    {
        $path = 'reports/report_1_10_2026-07-29_10-42-15.csv';
        Storage::disk('local')->put($path, 'csv-data');
        $completed = $this->process(
            pid: 1,
            status: ProcessStatusId::Completed,
            startedAt: '2026-07-29 10:00:00',
            path: $path,
        );

        $this->get(route('report-processes.download', $completed))
            ->assertOk()
            ->assertDownload(basename($path))
            ->assertStreamedContent('csv-data');

        $missing = $this->process(
            pid: 2,
            status: ProcessStatusId::Completed,
            startedAt: '2026-07-29 10:01:00',
            path: 'reports/report_2_10_2026-07-29_10-42-15.csv',
        );
        $running = $this->process(
            pid: 3,
            status: ProcessStatusId::Running,
            startedAt: '2026-07-29 10:02:00',
            path: $path,
        );
        $unsafe = $this->process(
            pid: 4,
            status: ProcessStatusId::Completed,
            startedAt: '2026-07-29 10:03:00',
            path: '../.env',
        );

        $this->get(route('report-processes.download', $missing))->assertNotFound();
        $this->get(route('report-processes.download', $running))->assertNotFound();
        $this->get(route('report-processes.download', $unsafe))->assertNotFound();
    }

    public function test_process_times_are_stored_in_utc_and_displayed_in_the_application_timezone(): void
    {
        $now = CarbonImmutable::parse('2026-07-29 00:30:00', 'Europe/Moscow');
        Date::setTestNow($now);
        $tracker = $this->app->make(ReportProcessTracker::class);
        $process = $tracker->start(123);

        try {
            self::assertSame($now->getTimestamp(), $process->fresh()->rp_start_datetime->getTimestamp());
            self::assertSame(0, $process->fresh()->rp_start_datetime->getOffset());
            $this->get(route('report-processes.index'))
                ->assertOk()
                ->assertSee('29.07.2026')
                ->assertSee('00:30:00');
        } finally {
            $tracker->release($process);
            Date::setTestNow();
        }
    }

    private function process(
        int $pid,
        ProcessStatusId $status,
        string $startedAt,
        ?string $path = null,
    ): ReportProcess {
        return ReportProcess::query()->create([
            'rp_pid' => $pid,
            'rp_start_datetime' => CarbonImmutable::parse($startedAt, config('app.timezone'))->utc(),
            'rp_exec_time' => $status === ProcessStatusId::Running ? null : '0.123',
            'ps_id' => $status,
            'rp_file_save_path' => $path,
        ]);
    }
}
