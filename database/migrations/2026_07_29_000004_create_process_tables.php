<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('process_status', function (Blueprint $table): void {
            $table->unsignedSmallInteger('ps_id')->primary();
            $table->string('ps_name')->unique();
        });

        Schema::create('report_process', function (Blueprint $table): void {
            $table->bigIncrements('rp_id');
            $table->unsignedBigInteger('rp_pid');
            $table->timestampTz('rp_start_datetime', 3);
            $table->decimal('rp_exec_time', 12, 3)->nullable();
            $table->unsignedSmallInteger('ps_id');
            $table->string('rp_file_save_path')->nullable();
            $table->timestamps();

            $table->foreign('ps_id')
                ->references('ps_id')
                ->on('process_status')
                ->restrictOnDelete();
            $table->index('rp_start_datetime');
            $table->index('ps_id');
            $table->index('rp_pid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_process');
        Schema::dropIfExists('process_status');
    }
};
