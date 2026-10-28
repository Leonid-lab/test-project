<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ProcessStatusId;
use App\Models\ReportProcess;
use App\Services\Reports\ReportProcessTracker;
use Database\Seeders\ProcessStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class RecoverReportsCommandTest extends TestCase
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

    public function test_interrupted_reports_are_failed_and_their_files_are_removed(): void
    {
        $interrupted = $this->process(ProcessStatusId::Running);
        $completed = $this->process(ProcessStatusId::Completed);
        $interruptedPath = 'reports/'.$interrupted->rp_id.'/report_1_10_2026-07-29_10-42-15.csv';
        $completedPath = 'reports/'.$completed->rp_id.'/report_1_10_2026-07-29_10-42-15.csv';
        Storage::disk('local')->put($interruptedPath, 'partial report');
        Storage::disk('local')->put(dirname($interruptedPath).'/.report.tmp', 'partial write');
        Storage::disk('local')->put($completedPath, 'completed report');
        $completed->update(['rp_file_save_path' => $completedPath]);

        $this->artisan('report:recover')->expectsOutput('Recovered reports: 1')->assertSuccessful();

        self::assertSame(ProcessStatusId::Failed, $interrupted->fresh()->ps_id);
        self::assertNull($interrupted->fresh()->rp_file_save_path);
        self::assertFalse(Storage::disk('local')->exists(dirname($interruptedPath)));
        Storage::disk('local')->assertExists($completedPath);
        self::assertSame(ProcessStatusId::Completed, $completed->fresh()->ps_id);
        $this->artisan('report:recover')->expectsOutput('Recovered reports: 0')->assertSuccessful();
    }

    public function test_active_reports_are_skipped_until_their_database_session_ends(): void
    {
        $process = $this->process(ProcessStatusId::Running);
        $path = 'reports/'.$process->rp_id.'/report_1_10_2026-07-29_10-42-15.csv';
        Storage::disk('local')->put($path, 'active report');
        config()->set('database.connections.report_worker', config('database.connections.pgsql'));
        $worker = DB::connection('report_worker');

        try {
            $worker->selectOne('SELECT pg_advisory_lock(?)', [$process->rp_id], false);
            $this->artisan('report:recover')->expectsOutput('Recovered reports: 0')->assertSuccessful();
            self::assertSame(ProcessStatusId::Running, $process->fresh()->ps_id);
            Storage::disk('local')->assertExists($path);
        } finally {
            unset($worker);
            DB::purge('report_worker');
        }

        $this->artisan('report:recover')->expectsOutput('Recovered reports: 1')->assertSuccessful();
        self::assertSame(ProcessStatusId::Failed, $process->fresh()->ps_id);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_reports_owned_by_the_current_session_are_not_recovered(): void
    {
        $tracker = $this->app->make(ReportProcessTracker::class);
        $process = $tracker->start(123);

        try {
            $this->artisan('report:recover')->expectsOutput('Recovered reports: 0')->assertSuccessful();
            self::assertSame(ProcessStatusId::Running, $process->fresh()->ps_id);
        } finally {
            $tracker->release($process);
        }

        $this->artisan('report:recover')->expectsOutput('Recovered reports: 1')->assertSuccessful();
    }

    private function process(ProcessStatusId $status): ReportProcess
    {
        return ReportProcess::query()->create([
            'rp_pid' => 123,
            'rp_start_datetime' => Date::now('UTC')->subMinute(),
            'ps_id' => $status,
        ]);
    }
}
