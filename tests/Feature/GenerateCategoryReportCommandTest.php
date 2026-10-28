<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\ReportManufacturer;
use App\Data\ReportRow;
use App\Enums\ProcessStatusId;
use App\Exceptions\ReportWriteException;
use App\Models\Manufacturer;
use App\Models\Price;
use App\Models\Product;
use App\Models\ReportProcess;
use App\Services\Reports\CsvReportWriter;
use Carbon\CarbonImmutable;
use Database\Seeders\ProcessStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use RuntimeException;
use Tests\TestCase;

final class GenerateCategoryReportCommandTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = ProcessStatusSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-07-29 10:42:15');
        Storage::fake('local');
        config()->set('reports.disk', 'local');
        config()->set('reports.directory', 'reports');
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    public function test_it_generates_one_ordered_report_per_manufacturer(): void
    {
        $firstManufacturer = Manufacturer::query()->create([
            'manufacturer_name' => '=Фабрика',
        ]);
        $secondManufacturer = Manufacturer::query()->create([
            'manufacturer_name' => 'Второй производитель',
        ]);
        $firstProduct = $this->product($firstManufacturer, 10, '+Основной товар');
        $singlePriceProduct = $this->product($firstManufacturer, 10, 'Одна цена');
        $noCurrentPriceProduct = $this->product($firstManufacturer, 10, 'Без цены');
        $secondProduct = $this->product($secondManufacturer, 10, 'Другой товар');
        $otherCategoryProduct = $this->product($secondManufacturer, 20, 'Чужая категория');

        $this->price($firstProduct, '10.0000', '2026-07-23');
        $this->price($firstProduct, '10.0000', '2026-07-24');
        $this->price($firstProduct, '20.0000', '2026-07-27');
        $this->price($firstProduct, '20.0000', '2026-07-29');
        $this->price($firstProduct, '1.0000', '2026-07-22');
        $this->price($singlePriceProduct, '10.5550', '2026-07-28');
        $this->price($noCurrentPriceProduct, '5.0000', '2026-07-22');
        $this->price($secondProduct, '9.0000', '2026-07-29');
        $this->price($otherCategoryProduct, '2.0000', '2026-07-29');

        $this->reportCommand(10)
            ->expectsOutputToContain('Period: 2026-07-23 — 2026-07-29')
            ->expectsOutputToContain('Manufacturers: 2')
            ->expectsOutputToContain('Files: 2')
            ->assertSuccessful();

        $processes = ReportProcess::query()->orderBy('rp_id')->get();
        self::assertCount(2, $processes);

        foreach ($processes as $process) {
            self::assertSame(ProcessStatusId::Completed, $process->ps_id);
            self::assertNotNull($process->rp_exec_time);
            self::assertIsString($process->rp_file_save_path);
            self::assertStringStartsWith('reports/'.$process->rp_id.'/report_', $process->rp_file_save_path);
            self::assertFalse(str_starts_with($process->rp_file_save_path, '/'));
            Storage::disk('local')->assertExists($process->rp_file_save_path);
        }

        $firstPath = sprintf(
            'reports/%d/report_%d_10_2026-07-29_10-42-15.csv',
            $processes[0]->rp_id,
            $firstManufacturer->manufacturer_id,
        );
        $secondPath = sprintf(
            'reports/%d/report_%d_10_2026-07-29_10-42-15.csv',
            $processes[1]->rp_id,
            $secondManufacturer->manufacturer_id,
        );

        self::assertStringStartsWith(
            "\xEF\xBB\xBF",
            Storage::disk('local')->get($firstPath),
        );

        $firstRows = $this->readCsv($firstPath);
        self::assertSame(
            ['manufacturer_name', 'product_name', 'price', 'price_date'],
            $firstRows[0],
        );
        self::assertCount(5, $firstRows);
        self::assertSame(
            [
                ["'=Фабрика", "'+Основной товар", '10.00', '2026-07-23'],
                ["'=Фабрика", "'+Основной товар", '20.00', '2026-07-29'],
                ["'=Фабрика", 'Одна цена', '10.56', '2026-07-28'],
                ["'=Фабрика", 'Одна цена', '10.56', '2026-07-28'],
            ],
            array_slice($firstRows, 1),
        );
        $secondRows = $this->readCsv($secondPath);
        self::assertCount(3, $secondRows);
        self::assertSame($secondRows[1], $secondRows[2]);
        self::assertSame('9.00', $secondRows[1][2]);
    }

    public function test_expected_input_and_catalog_failures_are_recorded_without_error_log(): void
    {
        $log = Log::spy();

        $this->reportCommand('invalid')
            ->expectsOutput('The category_id argument must be a positive integer.')
            ->assertFailed();

        $this->reportCommand(999)
            ->expectsOutput('Category 999 has no products.')
            ->assertFailed();

        $manufacturer = Manufacturer::query()->create(['manufacturer_name' => 'Нет цен']);
        $product = $this->product($manufacturer, 30, 'Товар');
        $this->price($product, '1.0000', '2026-07-22');

        $this->reportCommand(30)
            ->expectsOutput('No prices were found for the selected seven-day period.')
            ->assertFailed();

        self::assertSame(3, ReportProcess::query()->count());
        self::assertSame(
            3,
            ReportProcess::query()->where('ps_id', ProcessStatusId::Failed->value)->count(),
        );
        $log->shouldNotHaveReceived('error');
    }

    public function test_fatal_writer_failure_marks_process_failed_and_is_logged_once(): void
    {
        $manufacturer = Manufacturer::query()->create(['manufacturer_name' => 'Ошибка']);
        $product = $this->product($manufacturer, 40, 'Товар');
        $this->price($product, '1.0000', '2026-07-29');

        $writer = new class extends CsvReportWriter
        {
            public function write(
                ReportManufacturer $manufacturer,
                int $categoryId,
                CarbonImmutable $generatedAt,
                iterable $rows,
                int $processId,
            ): string {
                throw new ReportWriteException('Disk failure.');
            }
        };
        $this->app->instance(CsvReportWriter::class, $writer);
        Log::expects('error')->once();

        $this->reportCommand(40)
            ->expectsOutputToContain('Report generation failed for manufacturer')
            ->assertFailed();

        $process = ReportProcess::query()->sole();
        self::assertSame(ProcessStatusId::Failed, $process->ps_id);
        self::assertNull($process->rp_file_save_path);
        self::assertNotNull($process->rp_exec_time);
        self::assertSame([], Storage::disk('local')->allFiles('reports'));
    }

    public function test_reports_generated_in_the_same_second_have_separate_files(): void
    {
        $manufacturer = Manufacturer::query()->create(['manufacturer_name' => 'Фабрика']);
        $product = $this->product($manufacturer, 10, 'Товар');
        $this->price($product, '10.0000', '2026-07-29');

        $this->reportCommand(10)->assertSuccessful();
        $this->price($product, '20.0000', '2026-07-29');
        $this->reportCommand(10)->assertSuccessful();

        $processes = ReportProcess::query()->orderBy('rp_id')->get();
        self::assertCount(2, $processes);
        $firstPath = $processes[0]->rp_file_save_path;
        $secondPath = $processes[1]->rp_file_save_path;
        self::assertIsString($firstPath);
        self::assertIsString($secondPath);
        self::assertNotSame($firstPath, $secondPath);
        self::assertSame(basename($firstPath), basename($secondPath));
        self::assertSame('10.00', $this->readCsv($firstPath)[2][2]);
        self::assertSame('20.00', $this->readCsv($secondPath)[2][2]);

        foreach ($processes as $process) {
            self::assertSame(ProcessStatusId::Completed, $process->ps_id);
            $this->get(route('report-processes.download', $process))
                ->assertOk()
                ->assertDownload(basename($process->rp_file_save_path));
        }
    }

    public function test_a_report_is_removed_when_saving_its_completed_status_fails(): void
    {
        $manufacturer = Manufacturer::query()->create(['manufacturer_name' => 'Фабрика']);
        $product = $this->product($manufacturer, 10, 'Товар');
        $this->price($product, '10.0000', '2026-07-29');
        ReportProcess::updating(static function (ReportProcess $process): void {
            if ($process->ps_id === ProcessStatusId::Completed) {
                throw new RuntimeException('Unable to save the completed status.');
            }
        });
        Log::expects('error')->once();

        try {
            $this->reportCommand(10)
                ->expectsOutputToContain('Report generation failed for manufacturer')
                ->assertFailed();

            $process = ReportProcess::query()->sole();
            self::assertSame(ProcessStatusId::Failed, $process->ps_id);
            self::assertNull($process->rp_file_save_path);
            self::assertSame([], Storage::disk('local')->allFiles('reports'));
        } finally {
            ReportProcess::flushEventListeners();
        }
    }

    public function test_a_partial_csv_is_removed_when_reading_rows_fails(): void
    {
        $rows = (static function (): iterable {
            yield new ReportRow(1, 'Фабрика', 1, 'Товар', '10.00', '2026-07-29', 1);

            throw new RuntimeException('Unable to read the remaining prices.');
        })();

        try {
            $this->app->make(CsvReportWriter::class)->write(
                new ReportManufacturer(1, 'Фабрика'),
                10,
                CarbonImmutable::parse('2026-07-29 10:42:15'),
                $rows,
                1,
            );
            self::fail('Writing an incomplete report must fail.');
        } catch (ReportWriteException $exception) {
            self::assertInstanceOf(RuntimeException::class, $exception->getPrevious());
            self::assertSame(
                'Unable to read the remaining prices.',
                $exception->getPrevious()->getMessage(),
            );
        }

        self::assertSame([], Storage::disk('local')->allFiles('reports'));
    }

    private function product(
        Manufacturer $manufacturer,
        int $categoryId,
        string $name,
    ): Product {
        return Product::query()->create([
            'product_name' => $name,
            'category_id' => $categoryId,
            'manufacturer_id' => $manufacturer->manufacturer_id,
        ]);
    }

    private function price(Product $product, string $value, string $date): Price
    {
        return Price::query()->create([
            'product_id' => $product->product_id,
            'price' => $value,
            'price_date' => $date,
        ]);
    }

    /**
     * @return list<list<string>>
     */
    private function readCsv(string $path): array
    {
        $handle = fopen(Storage::disk('local')->path($path), 'rb');
        self::assertIsResource($handle);
        $rows = [];

        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $cells = [];

            foreach ($row as $cell) {
                if (! is_string($cell)) {
                    self::fail('CSV contains a null cell.');
                }

                $cells[] = $cell;
            }

            $rows[] = $cells;
        }

        fclose($handle);

        if (isset($rows[0][0])) {
            $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', $rows[0][0]) ?? $rows[0][0];
        }

        return $rows;
    }

    private function reportCommand(int|string $categoryId): PendingCommand
    {
        $command = $this->artisan('report:generate', ['category_id' => $categoryId]);

        if (is_int($command)) {
            self::fail('Console output mocking is disabled.');
        }

        return $command;
    }
}
