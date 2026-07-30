<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Manufacturer extends Model
{
    protected $table = 'manufacturer';

    protected $primaryKey = 'manufacturer_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'manufacturer_id' => 'integer',
        ];
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'manufacturer_id', 'manufacturer_id');
    }
}
