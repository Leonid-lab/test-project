<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Data\ReportManufacturer;
use App\Data\ReportRow;
use App\Exceptions\ReportWriteException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CsvReportWriter
{
    private const UTF8_BOM = "\xEF\xBB\xBF";

    /**
     * Записывает отчёт производителя во временный файл и публикует его
     * атомарно только после успешной записи всех строк.
     *
     * @param  iterable<ReportRow>  $rows
     */
    public function write(
        ReportManufacturer $manufacturer,
        int $categoryId,
        CarbonImmutable $generatedAt,
        iterable $rows,
    ): string {
        $disk = $this->disk();
        $directory = $this->directory();
        $filename = sprintf(
            'report_%d_%d_%s.csv',
            $manufacturer->id,
            $categoryId,
            $generatedAt->format('Y-m-d_H-i-s'),
        );
        $finalPath = $directory.'/'.$filename;
        $temporaryPath = $directory.'/.'.$filename.'.'.bin2hex(random_bytes(8)).'.tmp';

        if ($this->fileExists($disk, $finalPath)) {
            throw new ReportWriteException("Report file already exists: {$filename}");
        }

        if (! $disk->exists($directory) && ! $disk->makeDirectory($directory)) {
            throw new ReportWriteException('Unable to create the report directory.');
        }

        $handle = null;

        try {
            $handle = fopen($disk->path($temporaryPath), 'x+b');

            if ($handle === false) {
                throw new ReportWriteException('Unable to create a temporary report file.');
            }

            if (fwrite($handle, self::UTF8_BOM) !== strlen(self::UTF8_BOM)) {
                throw new ReportWriteException('Unable to write the CSV encoding marker.');
            }

            $this->writeRow($handle, [
                'manufacturer_name',
                'product_name',
                'price',
                'price_date',
            ]);

            foreach ($rows as $row) {
                $this->writeRow($handle, [
                    $this->safeText($row->manufacturerName),
                    $this->safeText($row->productName),
                    $row->price,
                    $row->priceDate,
                ]);
            }

            if (! fflush($handle)) {
                throw new ReportWriteException('Unable to flush the report file.');
            }

            if (! fclose($handle)) {
                throw new ReportWriteException('Unable to close the report file.');
            }
            $handle = null;

            if ($this->fileExists($disk, $finalPath)) {
                throw new ReportWriteException("Report file already exists: {$filename}");
            }

            if (! rename($disk->path($temporaryPath), $disk->path($finalPath))) {
                throw new ReportWriteException('Unable to atomically move the report file.');
            }

            return $finalPath;
        } catch (Throwable $exception) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            $this->removeTemporaryFile($disk, $temporaryPath, $exception);

            if ($exception instanceof ReportWriteException) {
                throw $exception;
            }

            throw new ReportWriteException('Unable to write the report file.', 0, $exception);
        }
    }

    /**
     * @param  resource  $handle
     * @param  list<string>  $row
     */
    private function writeRow(mixed $handle, array $row): void
    {
        if (fputcsv($handle, $row, ',', '"', '') === false) {
            throw new ReportWriteException('Unable to write a CSV row.');
        }
    }

    private function safeText(string $value): string
    {
        // Не даём табличным редакторам интерпретировать текст как формулу.
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
            return "'".$value;
        }

        return $value;
    }

    private function disk(): Filesystem
    {
        $diskName = config('reports.disk');

        if (! is_string($diskName) || $diskName === '') {
            throw new ReportWriteException('The reports disk is not configured.');
        }

        return Storage::disk($diskName);
    }

    private function directory(): string
    {
        $directory = config('reports.directory');

        if (! is_string($directory) || $directory === '' || str_contains($directory, '..')) {
            throw new ReportWriteException('The reports directory is not configured safely.');
        }

        return trim($directory, '/');
    }

    private function removeTemporaryFile(
        Filesystem $disk,
        string $temporaryPath,
        Throwable $original,
    ): void {
        try {
            if ($disk->exists($temporaryPath) && ! $disk->delete($temporaryPath)) {
                throw new ReportWriteException(
                    'Unable to remove the temporary report file.',
                    0,
                    $original,
                );
            }
        } catch (Throwable $cleanupException) {
            throw new ReportWriteException(
                'Report generation failed and the temporary file could not be removed.',
                0,
                $original,
            );
        }
    }

    private function fileExists(Filesystem $disk, string $path): bool
    {
        return $disk->exists($path);
    }
}
