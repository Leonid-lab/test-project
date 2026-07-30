<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Price extends Model
{
    protected $table = 'price';

    protected $primaryKey = 'price_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price_id' => 'integer',
            'product_id' => 'integer',
            'price' => 'decimal:4',
            'price_date' => 'immutable_date',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }
}
