<?php

namespace App\Exports;

use App\Models\Invoice;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Reproduces the original hand-made invoice spreadsheet: same cell
 * positions, orange header bands, and live formulas so the contractor
 * can still tweak it in Excel/Sheets before sending.
 */
class InvoiceSheetExport implements FromArray, WithColumnWidths, WithEvents, WithTitle
{
    private const LINE_ROWS = 10;

    private const FIRST_LINE_ROW = 13;

    private const ORANGE = 'F5A962';

    private const PEACH = 'FBE3C6';

    public function __construct(private readonly Invoice $invoice) {}

    public function title(): string
    {
        return 'Invoice '.str_replace(['/', '\\', '?', '*', '[', ']', ':'], '-', $this->invoice->invoice_number);
    }

    public function columnWidths(): array
    {
        return ['A' => 46, 'B' => 11, 'C' => 11, 'D' => 18];
    }

    public function array(): array
    {
        $inv = $this->invoice->loadMissing(['contractorProfile', 'client', 'lines']);
        $profile = $inv->contractorProfile;
        $client = $inv->client;

        $from = array_pad($profile->addressLines(), 4, '');
        $to = array_pad([$client->name, ...$client->addressLines()], 5, '');

        $rows = [
            ['Name: '.$profile->billing_name, '', '', 'Invoice'],
            [$from[0], '', '', 'Invoice #'.$inv->invoice_number],
            [$from[1], '', '', $inv->invoice_date->format('F j, Y')],
            [$from[2] !== '' && $from[2] !== 'Canada' ? $from[2] : '', '', '', ''],
            [$to[0], '', '', ''],
            [$to[1], '', '', ''],
            [$to[2], '', '', 'PAYMENT TERMS'],
            [$to[3], '', '', $profile->payment_terms],
            [$to[4] ?: $profile->phone, '', '', ''],
            ['', '', '', ''],
            ['', '', '', ''],
            ['DESCRIPTION', 'HOURS', 'RATE', 'LINE TOTAL'],
        ];

        $lines = $inv->lines->values();
        for ($i = 0; $i < self::LINE_ROWS; $i++) {
            $row = self::FIRST_LINE_ROW + $i;
            $line = $lines->get($i);

            $rows[] = [
                $line?->description ?? '',
                $line?->hours !== null ? (float) $line->hours : ($line ? '' : 0),
                $line?->rate !== null ? (float) $line->rate : ($line ? '' : 0),
                "=B{$row}*C{$row}",
            ];
        }

        $first = self::FIRST_LINE_ROW;
        $last = self::FIRST_LINE_ROW + self::LINE_ROWS - 1;
        $subtotalRow = $last + 1;
        $taxRow = $last + 2;
        $totalRow = $last + 3;

        $rows[] = ['', "=SUM(B{$first}:B{$last})", 'SUBTOTAL', "=SUM(D{$first}:D{$last})"];
        $rows[] = ['', '', 'SALES TAX', "=ROUND(D{$subtotalRow}*".((float) $inv->tax_rate).',2)'];
        $rows[] = ['', '', 'TOTAL', "=D{$subtotalRow}+D{$taxRow}"];

        if ($profile->tax_number) {
            $rows[] = ['', '', '', ''];
            $rows[] = ['GST/HST #: '.$profile->tax_number, '', '', ''];
        }

        return $rows;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $this->style($event->sheet->getDelegate());
            },
        ];
    }

    private function style(Worksheet $s): void
    {
        $first = self::FIRST_LINE_ROW;
        $last = $first + self::LINE_ROWS - 1;
        $subtotalRow = $last + 1;
        $totalRow = $last + 3;

        $s->getDefaultRowDimension()->setRowHeight(18);
        $s->getStyle('A1:D'.($totalRow + 2))->getFont()->setName('Arial')->setSize(10);

        // Header block
        $s->getStyle('A1')->getFont()->setBold(true)->setSize(12);
        $s->getStyle('D1')->getFont()->setBold(true)->setSize(14);
        $s->getStyle('D1:D3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $s->getStyle('A5')->getFont()->setBold(true);

        // Payment terms box
        $s->getStyle('D7')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::PEACH);
        $s->getStyle('D7')->getFont()->setBold(true);
        $s->getStyle('D7:D8')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Line header band
        $s->getStyle('A12:D12')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::ORANGE);
        $s->getStyle('A12:D12')->getFont()->setBold(true);
        $s->getStyle('A12')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $s->getStyle('B12:D12')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        // Line grid
        $s->getStyle("A{$first}:D{$last}")->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('EDEDED');
        $s->getStyle("B{$first}:C{$last}")->getNumberFormat()->setFormatCode('0.##');
        $s->getStyle("D{$first}:D{$last}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_CURRENCY_USD);

        // Totals block
        $s->getStyle("B{$subtotalRow}:C{$totalRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::PEACH);
        $s->getStyle("B{$subtotalRow}:C{$totalRow}")->getFont()->setBold(true);
        $s->getStyle("C{$subtotalRow}:C{$totalRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $s->getStyle("B{$subtotalRow}")->getNumberFormat()->setFormatCode('0.##');
        $s->getStyle("D{$subtotalRow}:D{$totalRow}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_CURRENCY_USD);
        $s->getStyle("D{$totalRow}")->getFont()->setBold(true);

        // Bottom strip, like the sheet
        $strip = $totalRow + 1;
        $s->getStyle("A{$strip}:D{$strip}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::ORANGE);
        $s->getRowDimension($strip)->setRowHeight(8);

        $s->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
    }
}
