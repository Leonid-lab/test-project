<?php

declare(strict_types=1);

namespace App\Data;

final readonly class ReportRow
{
    public function __construct(
        public int $manufacturerId,
        public string $manufacturerName,
        public int $productId,
        public string $productName,
        public string $price,
        public string $priceDate,
        public int $rowOrder,
    ) {}
}
