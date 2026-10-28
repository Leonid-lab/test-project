<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Data\ReportManufacturer;
use App\Data\ReportRow;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use stdClass;
use Throwable;

final class PriceReportRepository
{
    /**
     * Проверяет наличие товаров независимо от цен за отчётный период.
     */
    public function categoryHasProducts(int $categoryId): bool
    {
        return DB::table('product')->where('category_id', $categoryId)->exists();
    }

    /**
     * Возвращает только производителей, для которых отчёт не будет пустым.
     *
     * @return list<ReportManufacturer>
     */
    public function reportableManufacturers(
        int $categoryId,
        CarbonImmutable $dateFrom,
        CarbonImmutable $dateTo,
    ): array {
        $records = DB::select(
            <<<'SQL'
                SELECT
                    m.manufacturer_id,
                    m.manufacturer_name
                FROM manufacturer m
                WHERE EXISTS (
                    SELECT 1
                    FROM product p
                    WHERE p.manufacturer_id = m.manufacturer_id
                      AND p.category_id = :category_id
                      AND EXISTS (
                          SELECT 1
                          FROM price pr
                          WHERE pr.product_id = p.product_id
                            AND pr.price_date BETWEEN :date_from AND :date_to
                      )
                )
                ORDER BY m.manufacturer_id
                SQL,
            [
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
                'category_id' => $categoryId,
            ],
        );

        return array_values(array_map(
            static fn (stdClass $record): ReportManufacturer => new ReportManufacturer(
                id: (int) $record->manufacturer_id,
                name: (string) $record->manufacturer_name,
            ),
            $records,
        ));
    }

    /**
     * Передаёт по две строки на товар, не загружая всю историю цен в PHP.
     *
     * Раздельное ранжирование однозначно обрабатывает совпадающие цены:
     * для минимума выбирается ранняя запись, для максимума — поздняя.
     *
     * @return Generator<int, ReportRow>
     */
    public function rows(
        int $categoryId,
        int $manufacturerId,
        CarbonImmutable $dateFrom,
        CarbonImmutable $dateTo,
    ): Generator {
        $connection = DB::connection();
        $cursor = 'report_'.bin2hex(random_bytes(8));
        $query = <<<'SQL'
                WITH target_products AS MATERIALIZED (
                    SELECT
                        p.product_id,
                        p.product_name,
                        p.manufacturer_id
                    FROM product p
                    WHERE p.category_id = :category_id
                      AND p.manufacturer_id = :manufacturer_id
                ),
                ranked_prices AS (
                    SELECT
                        pr.price_id,
                        pr.product_id,
                        pr.price,
                        pr.price_date,
                        ROW_NUMBER() OVER (
                            PARTITION BY pr.product_id
                            ORDER BY pr.price ASC, pr.price_date ASC, pr.price_id ASC
                        ) AS min_rank,
                        ROW_NUMBER() OVER (
                            PARTITION BY pr.product_id
                            ORDER BY pr.price DESC, pr.price_date DESC, pr.price_id DESC
                        ) AS max_rank
                    FROM price pr
                    INNER JOIN target_products p ON p.product_id = pr.product_id
                    WHERE pr.price_date BETWEEN :date_from AND :date_to
                ),
                report_rows AS (
                    SELECT
                        product_id,
                        price,
                        price_date,
                        1 AS row_order
                    FROM ranked_prices
                    WHERE min_rank = 1

                    UNION ALL

                    SELECT
                        product_id,
                        price,
                        price_date,
                        2 AS row_order
                    FROM ranked_prices
                    WHERE max_rank = 1
                )
                SELECT
                    m.manufacturer_id,
                    m.manufacturer_name,
                    p.product_id,
                    p.product_name,
                    TO_CHAR(ROUND(rr.price, 2), 'FM9999999999990.00') AS formatted_price,
                    rr.price_date::text AS price_date,
                    rr.row_order
                FROM report_rows rr
                INNER JOIN target_products p ON p.product_id = rr.product_id
                INNER JOIN manufacturer m ON m.manufacturer_id = p.manufacturer_id
                ORDER BY p.product_name, p.product_id, rr.row_order
                SQL;
        $connection->transaction(fn () => $connection->statement(
            "DECLARE {$cursor} NO SCROLL CURSOR WITH HOLD FOR ".$query,
            [
                'category_id' => $categoryId,
                'manufacturer_id' => $manufacturerId,
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
            ],
        ));
        try {
            do {
                $records = $connection->select("FETCH FORWARD 500 FROM {$cursor}", [], false);

                foreach ($records as $record) {
                    yield new ReportRow(
                        manufacturerId: (int) $record->manufacturer_id,
                        manufacturerName: (string) $record->manufacturer_name,
                        productId: (int) $record->product_id,
                        productName: (string) $record->product_name,
                        price: (string) $record->formatted_price,
                        priceDate: (string) $record->price_date,
                        rowOrder: (int) $record->row_order,
                    );
                }
            } while (count($records) === 500);
        } finally {
            try {
                $connection->statement("CLOSE {$cursor}");
            } catch (Throwable $exception) {
                Log::warning('Unable to close a report cursor.', ['exception' => $exception]);
            }
        }
    }
}
