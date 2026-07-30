<?php

declare(strict_types=1);

return [
    'disk' => env('REPORTS_DISK', 'local'),
    'directory' => env('REPORTS_DIRECTORY', 'reports'),
    'period_days' => 7,
];
