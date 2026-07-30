<?php

declare(strict_types=1);

namespace App\Data;

use Carbon\CarbonImmutable;

final readonly class CategoryReportResult
{
    /**
     * @param  list<string>  $paths
     */
    public function __construct(
        public bool $successful,
        public CarbonImmutable $dateFrom,
        public CarbonImmutable $dateTo,
        public int $manufacturerCount,
        public array $paths,
        public ?string $errorMessage = null,
    ) {}
}
