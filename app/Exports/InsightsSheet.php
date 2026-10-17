<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Single worksheet of pre-computed insight rows. The controller owns the
 * aggregation; this class only lays the result out for the spreadsheet.
 */
class InsightsSheet implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<int, string>  $headings
     */
    public function __construct(
        private readonly array $rows,
        private readonly array $headings,
        private readonly string $title,
    ) {}

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function title(): string
    {
        return $this->title;
    }
}
