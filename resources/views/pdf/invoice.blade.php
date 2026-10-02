<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @font-face {
        font-family: 'Sora';
        font-weight: 400;
        src: url('{{ storage_path('fonts/Sora-400.ttf') }}') format('truetype');
    }
    @font-face {
        font-family: 'Sora';
        font-weight: 500;
        src: url('{{ storage_path('fonts/Sora-500.ttf') }}') format('truetype');
    }
    @font-face {
        font-family: 'Sora';
        font-weight: 600;
        src: url('{{ storage_path('fonts/Sora-600.ttf') }}') format('truetype');
    }
    @font-face {
        font-family: 'Sora';
        font-weight: 700;
        src: url('{{ storage_path('fonts/Sora-700.ttf') }}') format('truetype');
    }

    @page { margin: 54px 60px 72px 60px; }

    /* No universal margin reset: dompdf applies `*` to the page context
       itself, which silently zeroes the @page margin. */
    * { box-sizing: border-box; }
    body, div, p, table, td, th, tr { margin: 0; padding: 0; }

    body {
        font-family: 'Sora', sans-serif;
        font-size: 12pt;
        color: #1a1a1a;
        line-height: 1.45;
    }

    .accent { color: #E8701A; }
    .muted { color: #6b7280; }

    .header { width: 100%; margin-bottom: 40px; }
    .header td { vertical-align: top; }

    .from-name { font-size: 12pt; font-weight: 600; margin-bottom: 8px; }
    .address { font-size: 10pt; line-height: 1.7; }

    .invoice-meta { text-align: right; }
    .invoice-word {
        font-size: 26pt;
        font-weight: 700;
        letter-spacing: -0.5px;
        margin-bottom: 6px;
    }
    .invoice-meta .number { font-weight: 600; font-size: 12pt; }
    .invoice-meta .date { font-size: 10pt; margin-bottom: 18px; }

    .terms-box {
        display: inline-block;
        background: #FBE3C6;
        padding: 8px 18px;
        text-align: center;
        min-width: 170px;
    }
    .terms-box .label {
        font-size: 8pt;
        font-weight: 600;
        letter-spacing: 1px;
        text-transform: uppercase;
        margin-bottom: 2px;
    }
    .terms-box .value { font-size: 10pt; font-weight: 500; }

    .bill-to { margin-bottom: 36px; }
    .bill-to .name { font-weight: 600; }

    table.lines { width: 100%; border-collapse: collapse; }
    table.lines th {
        background: #F5A962;
        color: #1a1a1a;
        font-size: 9pt;
        font-weight: 600;
        letter-spacing: 1px;
        text-transform: uppercase;
        padding: 9px 12px;
        text-align: left;
    }
    table.lines th.num, table.lines td.num { text-align: right; }
    table.lines th.center, table.lines td.center { text-align: center; }
    table.lines td {
        padding: 9px 12px;
        font-size: 10.5pt;
        border-bottom: 1px solid #f1f1f1;
    }
    table.lines tr.blank td { border-bottom: 1px solid #f1f1f1; height: 18px; padding-top: 6px; padding-bottom: 6px; }

    .footer { width: 100%; margin-top: 18px; }
    .footer td { vertical-align: bottom; }

    .total-hours {
        background: #FBE3C6;
        font-size: 12pt;
        font-weight: 600;
        text-align: center;
        padding: 10px 24px;
        display: inline-block;
    }

    table.totals { border-collapse: collapse; margin-left: auto; }
    table.totals td {
        padding: 8px 12px;
        font-size: 10.5pt;
    }
    table.totals td.label {
        background: #FBE3C6;
        font-weight: 600;
        font-size: 9pt;
        letter-spacing: 0.8px;
        text-transform: uppercase;
        text-align: right;
    }
    table.totals td.value { min-width: 120px; text-align: right; font-weight: 500; }
    table.totals tr.grand td.value { font-weight: 700; font-size: 11.5pt; }

    .bottom-strip {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        height: 14px;
        background: #F5A962;
    }
    .tax-number { margin-top: 36px; font-size: 9pt; }
</style>
</head>
<body>
    <table class="header">
        <tr>
            <td>
                <div class="from-name">Name: {{ $invoice->contractorProfile->billing_name }}</div>
                <div class="address">
                    @foreach ($invoice->contractorProfile->addressLines() as $line)
                        {{ $line }}<br>
                    @endforeach
                </div>
            </td>
            <td class="invoice-meta">
                <div class="invoice-word accent">Invoice</div>
                <div class="number">Invoice #{{ $invoice->invoice_number }}</div>
                <div class="date muted">{{ $invoice->invoice_date->format('F j, Y') }}</div>
                <div class="terms-box">
                    <div class="label">Payment Terms</div>
                    <div class="value">{{ $invoice->contractorProfile->payment_terms }}</div>
                </div>
            </td>
        </tr>
    </table>

    <div class="bill-to">
        <div class="name">{{ $invoice->client->name }}</div>
        <div class="address">
            @foreach ($invoice->client->addressLines() as $line)
                {{ $line }}<br>
            @endforeach
        </div>
    </div>

    <table class="lines">
        <thead>
            <tr>
                <th>Description</th>
                <th class="num" style="width: 80px;">Hours</th>
                <th class="num" style="width: 80px;">Rate</th>
                <th class="num" style="width: 120px;">Line Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->lines as $line)
                <tr>
                    <td>{{ $line->description }}</td>
                    <td class="num">{{ $line->hours !== null ? rtrim(rtrim(number_format((float) $line->hours, 2), '0'), '.') : '' }}</td>
                    <td class="num">{{ $line->rate !== null ? rtrim(rtrim(number_format((float) $line->rate, 2), '0'), '.') : '' }}</td>
                    <td class="num">${{ number_format((float) $line->amount, 2) }}</td>
                </tr>
            @endforeach
            @for ($i = $invoice->lines->count(); $i < 7; $i++)
                <tr class="blank"><td>&nbsp;</td><td></td><td></td><td></td></tr>
            @endfor
        </tbody>
    </table>

    <table class="footer">
        <tr>
            <td>
                <div class="total-hours">{{ rtrim(rtrim(number_format((float) $invoice->lines->sum('hours'), 2), '0'), '.') }}</div>
                @if ($invoice->contractorProfile->tax_number)
                    <div class="tax-number muted">GST/HST #: {{ $invoice->contractorProfile->tax_number }}</div>
                @endif
            </td>
            <td style="width: 300px;">
                <table class="totals">
                    <tr>
                        <td class="label">Subtotal</td>
                        <td class="value">${{ number_format((float) $invoice->subtotal, 2) }}</td>
                    </tr>
                    <tr>
                        <td class="label">Sales Tax</td>
                        <td class="value">${{ number_format((float) $invoice->tax_amount, 2) }}</td>
                    </tr>
                    <tr class="grand">
                        <td class="label">Total</td>
                        <td class="value">${{ number_format((float) $invoice->total, 2) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="bottom-strip"></div>
</body>
</html>
