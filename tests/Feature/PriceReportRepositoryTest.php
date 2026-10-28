<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Manufacturer;
use App\Repositories\PriceReportRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class PriceReportRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_rows_are_fetched_in_batches_from_a_stable_snapshot(): void
    {
        $manufacturerId = $this->catalog();
        $fetchQueries = [];
        DB::listen(static function (QueryExecuted $event) use (&$fetchQueries): void {
            if (str_starts_with($event->sql, 'FETCH FORWARD')) {
                $fetchQueries[] = $event->sql;
            }
        });
        $rows = $this->app->make(PriceReportRepository::class)->rows(
            10,
            $manufacturerId,
            CarbonImmutable::parse('2026-07-23'),
            CarbonImmutable::parse('2026-07-29'),
        );
        self::assertSame('10.00', $rows->current()->price);
        DB::table('price')->update(['price' => '999.0000']);
        $count = 0;
        $prices = [];

        foreach ($rows as $row) {
            $prices[$row->price] = true;
            $count++;
        }

        self::assertSame(1202, $count);
        self::assertSame(['10.00', '20.00'], array_keys($prices));
        self::assertCount(3, $fetchQueries);
        self::assertSame(0, DB::selectOne("SELECT count(*) AS count FROM pg_cursors WHERE name LIKE 'report_%'")->count);
    }

    public function test_the_cursor_is_closed_when_the_caller_stops_reading(): void
    {
        $rows = $this->app->make(PriceReportRepository::class)->rows(
            10,
            $this->catalog(),
            CarbonImmutable::parse('2026-07-23'),
            CarbonImmutable::parse('2026-07-29'),
        );
        $rows->current();
        self::assertSame(1, DB::selectOne("SELECT count(*) AS count FROM pg_cursors WHERE name LIKE 'report_%'")->count);

        unset($rows);

        self::assertSame(0, DB::selectOne("SELECT count(*) AS count FROM pg_cursors WHERE name LIKE 'report_%'")->count);
    }

    public function test_a_fetch_failure_closes_the_cursor_and_preserves_the_error(): void
    {
        $manufacturerId = $this->catalog();
        $fetchCount = 0;
        DB::connection()->beforeExecuting(static function (string $query) use (&$fetchCount): void {
            if (str_starts_with($query, 'FETCH FORWARD') && ++$fetchCount === 2) {
                throw new RuntimeException('Unable to fetch report rows.');
            }
        });

        $rowCount = 0;

        try {
            foreach ($this->app->make(PriceReportRepository::class)->rows(
                10,
                $manufacturerId,
                CarbonImmutable::parse('2026-07-23'),
                CarbonImmutable::parse('2026-07-29'),
            ) as $row) {
                $rowCount++;
            }

            self::fail('A failed fetch must stop the report.');
        } catch (RuntimeException $exception) {
            self::assertSame('Unable to fetch report rows.', $exception->getMessage());
        }

        self::assertSame(500, $rowCount);
        self::assertSame(0, DB::selectOne("SELECT count(*) AS count FROM pg_cursors WHERE name LIKE 'report_%'")->count);
    }

    private function catalog(): int
    {
        $manufacturer = Manufacturer::query()->create(['manufacturer_name' => 'Фабрика']);
        DB::insert(
            "INSERT INTO product (product_name, category_id, manufacturer_id) SELECT 'Product ' || i, 10, ? FROM generate_series(1, 601) i",
            [$manufacturer->manufacturer_id],
        );
        DB::insert(
            "INSERT INTO price (product_id, price, price_date) SELECT product_id, value, DATE '2026-07-29' FROM product CROSS JOIN (VALUES (10.0000), (20.0000)) AS prices(value)",
        );

        return $manufacturer->manufacturer_id;
    }
}
