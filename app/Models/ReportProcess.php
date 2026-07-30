<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProcessStatusId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $rp_id
 * @property int $rp_pid
 * @property CarbonImmutable $rp_start_datetime
 * @property string|null $rp_exec_time
 * @property ProcessStatusId $ps_id
 * @property string|null $rp_file_save_path
 * @property-read ProcessStatus $status
 */
final class ReportProcess extends Model
{
    protected $table = 'report_process';

    protected $primaryKey = 'rp_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rp_id' => 'integer',
            'rp_pid' => 'integer',
            'rp_start_datetime' => 'immutable_datetime',
            'rp_exec_time' => 'decimal:3',
            'ps_id' => ProcessStatusId::class,
        ];
    }

    /** @return BelongsTo<ProcessStatus, $this> */
    public function status(): BelongsTo
    {
        return $this->belongsTo(ProcessStatus::class, 'ps_id', 'ps_id');
    }

    public function getRouteKeyName(): string
    {
        return 'rp_id';
    }
}
