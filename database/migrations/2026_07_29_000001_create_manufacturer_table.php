<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manufacturer', function (Blueprint $table): void {
            $table->bigIncrements('manufacturer_id');
            $table->string('manufacturer_name');
            $table->timestamps();

            $table->index('manufacturer_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manufacturer');
    }
};
