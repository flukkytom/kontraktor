<x-filament-panels::page>
    @php $draft = $this->draftObject; $profile = $this->profile; @endphp

    {{-- Step indicator --}}
    <ol class="k-steps">
        <li @class(['k-step', 'k-step-active' => ! $draft, 'k-step-done' => $draft])>
            <span class="k-step-num">1</span> Choose period &amp; hours
        </li>
        <li @class(['k-step', 'k-step-active' => $draft])>
            <span class="k-step-num">2</span> Review &amp; create
        </li>
    </ol>

    @if (! $draft)
        <form wire:submit="preview" class="space-y-6">
            {{ $this->form }}

            <x-filament::button type="submit" size="lg">
                <span wire:loading.remove wire:target="preview">Preview invoice</span>
                <span wire:loading wire:target="preview">Preparing…</span>
            </x-filament::button>
        </form>
    @else
        @php
            $client = $profile->client;
            $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 2), '0'), '.');
        @endphp

        <div class="k-preview-wrap">
            {{-- The invoice, as it will render --}}
            <div class="k-invoice">
                <div class="k-invoice-head">
                    <div>
                        <div class="k-inv-name">{{ $profile->billing_name }}</div>
                        <div class="k-inv-addr">
                            @foreach ($profile->addressLines() as $line)<div>{{ $line }}</div>@endforeach
                        </div>
                    </div>
                    <div class="k-inv-meta">
                        <div class="k-inv-word">Invoice</div>
                        <label class="k-inv-number k-inv-number-edit">
                            <span>Invoice #</span>
                            <input
                                type="text"
                                wire:model.live.debounce.400ms="invoiceNumber"
                                class="k-inv-number-input"
                                maxlength="40"
                                aria-label="Invoice number"
                            />
                        </label>
                        @error('invoiceNumber')<div class="k-inv-error">{{ $message }}</div>@enderror
                        <div class="k-inv-date">{{ $draft->invoiceDate->format('F j, Y') }}</div>
                        <div class="k-inv-terms">
                            <div class="k-inv-terms-label">Payment terms</div>
                            <div>{{ $profile->payment_terms }}</div>
                        </div>
                    </div>
                </div>

                <div class="k-inv-billto">
                    <div class="k-inv-name">{{ $client->name }}</div>
                    <div class="k-inv-addr">
                        @foreach ($client->addressLines() as $line)<div>{{ $line }}</div>@endforeach
                    </div>
                </div>

                <table class="k-inv-table">
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th class="k-num">Hours</th>
                            <th class="k-num">Rate</th>
                            <th class="k-num">Line total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($draft->lines as $line)
                            <tr>
                                <td>{{ $line['description'] }}</td>
                                <td class="k-num">{{ $line['hours'] !== null ? $fmt($line['hours']) : '' }}</td>
                                <td class="k-num">{{ $line['rate'] !== null ? $fmt($line['rate']) : '' }}</td>
                                <td class="k-num">${{ number_format($line['amount'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <div class="k-inv-foot">
                    <div class="k-inv-hours">
                        <span class="k-inv-hours-num">{{ $fmt($draft->hours()) }}</span>
                        <span class="k-inv-hours-label">hours</span>
                    </div>
                    <table class="k-inv-totals">
                        <tr><td class="k-label">Subtotal</td><td class="k-num">${{ number_format($draft->subtotal(), 2) }}</td></tr>
                        <tr><td class="k-label">Sales tax ({{ $fmt($draft->taxRate * 100) }}%)</td><td class="k-num">${{ number_format($draft->tax(), 2) }}</td></tr>
                        <tr class="k-grand"><td class="k-label">Total</td><td class="k-num">${{ number_format($draft->total(), 2) }}</td></tr>
                    </table>
                </div>
            </div>

            {{-- Side rail --}}
            <aside class="k-preview-aside">
                <x-filament::section heading="Summary">
                    <dl class="k-dl">
                        <dt>Period</dt>
                        <dd>{{ $draft->periodStart->format('M j') }} – {{ $draft->periodEnd->format('M j, Y') }}</dd>
                        <dt>Source</dt>
                        <dd>{{ $draft->source->getLabel() }}</dd>
                        <dt>Invoice #</dt>
                        <dd>
                            {{ $invoiceNumber !== '' ? $invoiceNumber : '—' }}
                            @if ($invoiceNumber === $draft->invoiceNumber)
                                <span class="k-muted">(next in sequence)</span>
                            @else
                                <span class="k-muted">(custom · suggested {{ $draft->invoiceNumber }})</span>
                            @endif
                        </dd>
                        <dt>Lines</dt>
                        <dd>{{ count($draft->lines) }}</dd>
                        @if ($draft->source === \App\Enums\InvoiceSource::Harvest && $draft->sourcePayload)
                            <dt>Harvest entries</dt>
                            <dd>{{ count($draft->sourcePayload['entries']) }} <span class="k-muted">kept for audit</span></dd>
                        @endif
                    </dl>
                </x-filament::section>

                <x-filament::section>
                    <div class="k-cta">
                        <div class="k-conn-title">Looks right?</div>
                        <div class="k-conn-sub">Creates it as a draft. The invoice number is editable above — click it to change.</div>
                        <x-filament::button wire:click="confirm" size="lg" class="k-cta-btn">
                            <span wire:loading.remove wire:target="confirm">Create invoice {{ $invoiceNumber !== '' ? $invoiceNumber : '' }}</span>
                            <span wire:loading wire:target="confirm">Creating…</span>
                        </x-filament::button>
                        <x-filament::button wire:click="back" color="gray" outlined class="k-cta-btn">
                            Back and change
                        </x-filament::button>
                    </div>
                </x-filament::section>
            </aside>
        </div>
    @endif
</x-filament-panels::page>
