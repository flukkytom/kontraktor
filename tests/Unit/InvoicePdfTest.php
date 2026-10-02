<?php

namespace Tests\Unit;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Services\InvoicePdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class InvoicePdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_a_pdf_document(): void
    {
        $invoice = Invoice::factory()->create([
            'subtotal' => '6240.00',
            'tax_amount' => '312.00',
            'total' => '6552.00',
        ]);
        InvoiceLine::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Hours for September 1st - 15th, 2026',
            'hours' => 78,
            'rate' => 80,
            'amount' => 6240.00,
        ]);

        $pdf = app(InvoicePdf::class)->render($invoice);

        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_download_returns_a_streamed_response(): void
    {
        $invoice = Invoice::factory()->create();

        // Livewire file downloads must be StreamedResponse — a plain Response
        // carrying binary breaks JSON serialization (malformed UTF-8 500s).
        $this->assertInstanceOf(
            StreamedResponse::class,
            app(InvoicePdf::class)->download($invoice)
        );
    }
}
