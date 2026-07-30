<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Product extends Model
{
    protected $table = 'product';

    protected $primaryKey = 'product_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'category_id' => 'integer',
            'manufacturer_id' => 'integer',
        ];
    }

    /** @return BelongsTo<Manufacturer, $this> */
    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(Manufacturer::class, 'manufacturer_id', 'manufacturer_id');
    }

    /** @return HasMany<Price, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(Price::class, 'product_id', 'product_id');
    }
}
