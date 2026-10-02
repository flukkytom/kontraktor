<?php

namespace Tests\Unit;

use App\Exports\InvoiceSheetExport;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class InvoiceSheetExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_sheet_matches_the_original_layout_and_formulas_compute(): void
    {
        $invoice = Invoice::factory()->create(['invoice_number' => '09-2', 'tax_rate' => 0.05]);
        InvoiceLine::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Hours for September 1st - 15th, 2026',
            'hours' => 78,
            'rate' => 80,
            'amount' => 6240,
        ]);

        Excel::store(new InvoiceSheetExport($invoice), 'test-invoice.xlsx', 'local');
        $path = Storage::disk('local')->path('test-invoice.xlsx');

        $sheet = IOFactory::load($path)->getActiveSheet();
        Storage::disk('local')->delete('test-invoice.xlsx');

        // Header block in the same cells as the hand-made sheet.
        $this->assertStringStartsWith('Name: ', $sheet->getCell('A1')->getValue());
        $this->assertSame('Invoice', $sheet->getCell('D1')->getValue());
        $this->assertSame('Invoice #09-2', $sheet->getCell('D2')->getValue());
        $this->assertSame('PAYMENT TERMS', $sheet->getCell('D7')->getValue());
        $this->assertSame('DESCRIPTION', $sheet->getCell('A12')->getValue());

        // First line row and its live formula.
        $this->assertSame('Hours for September 1st - 15th, 2026', $sheet->getCell('A13')->getValue());
        $this->assertEquals(78, $sheet->getCell('B13')->getValue());
        $this->assertEquals(80, $sheet->getCell('C13')->getValue());
        $this->assertSame('=B13*C13', $sheet->getCell('D13')->getValue());
        $this->assertEquals(6240, $sheet->getCell('D13')->getCalculatedValue());

        // Totals: ten line rows (13–22) then subtotal/tax/total on 23–25.
        $this->assertSame('SUBTOTAL', $sheet->getCell('C23')->getValue());
        $this->assertEquals(78, $sheet->getCell('B23')->getCalculatedValue());
        $this->assertEquals(6240, $sheet->getCell('D23')->getCalculatedValue());
        $this->assertEquals(312, $sheet->getCell('D24')->getCalculatedValue());
        $this->assertEquals(6552, $sheet->getCell('D25')->getCalculatedValue());
    }
}
