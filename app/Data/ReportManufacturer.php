<?php

declare(strict_types=1);

namespace App\Data;

final readonly class ReportManufacturer
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}
}
