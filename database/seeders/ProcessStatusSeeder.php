<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ProcessStatusId;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class ProcessStatusSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('process_status')->upsert(
            array_map(
                static fn (ProcessStatusId $status): array => [
                    'ps_id' => $status->value,
                    'ps_name' => $status->label(),
                ],
                ProcessStatusId::cases(),
            ),
            ['ps_id'],
            ['ps_name'],
        );
    }
}
