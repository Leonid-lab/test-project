<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price', function (Blueprint $table): void {
            $table->bigIncrements('price_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('price', 14, 4);
            $table->date('price_date');
            $table->timestamps();

            $table->foreign('product_id')
                ->references('product_id')
                ->on('product')
                ->cascadeOnDelete();
            $table->index(
                ['product_id', 'price_date', 'price', 'price_id'],
                'price_product_period_extrema_idx',
            );
        });

        DB::statement('ALTER TABLE price ADD CONSTRAINT price_non_negative CHECK (price >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('price');
    }
};
