<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProcessStatusId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ProcessStatus extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'process_status';

    protected $primaryKey = 'ps_id';

    protected $keyType = 'int';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'ps_id' => ProcessStatusId::class,
        ];
    }

    /** @return HasMany<ReportProcess, $this> */
    public function reportProcesses(): HasMany
    {
        return $this->hasMany(ReportProcess::class, 'ps_id', 'ps_id');
    }
}
