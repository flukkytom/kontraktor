<?php

namespace App\Services;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoicePdf
{
    /**
     * Render the invoice PDF and return the raw binary.
     */
    public function render(Invoice $invoice): string
    {
        return $this->pdf($invoice)->output();
    }

    /**
     * Render and persist the PDF to the local disk; returns the path.
     */
    public function store(Invoice $invoice): string
    {
        $path = "invoices/{$invoice->id}.pdf";

        Storage::put($path, $this->render($invoice));
        $invoice->forceFill(['pdf_path' => $path])->save();

        return $path;
    }

    /**
     * Must return a StreamedResponse: Livewire base64-encodes those into
     * effects.download. Dompdf's ->download() returns a plain Response with
     * raw binary, which json_encode rejects as malformed UTF-8.
     */
    public function download(Invoice $invoice): StreamedResponse
    {
        $this->pdf($invoice); // eager-loads relations used by filename()

        return response()->streamDownload(
            fn () => print ($this->render($invoice)),
            static::filename($invoice, 'pdf'),
            ['Content-Type' => 'application/pdf'],
        );
    }

    public static function filename(Invoice $invoice, string $ext): string
    {
        $name = str($invoice->contractorProfile->billing_name)->slug();

        return "invoice-{$invoice->invoice_number}-{$name}.{$ext}";
    }

    private function pdf(Invoice $invoice)
    {
        $invoice->loadMissing(['contractorProfile.user', 'client', 'lines']);

        return Pdf::setOptions([
            'isRemoteEnabled' => false,
            'chroot' => [base_path(), storage_path()],
        ])
            ->loadView('pdf.invoice', ['invoice' => $invoice])
            ->setPaper('letter');
    }
}
