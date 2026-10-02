<x-filament-panels::page>
    {{-- Connection status --}}
    @if ($connection === null)
        <x-filament::section>
            <div class="k-conn k-conn-none">
                <x-filament::icon icon="heroicon-o-link-slash" class="k-conn-icon" />
                <div>
                    <div class="k-conn-title">Harvest is not connected</div>
                    <div class="k-conn-sub">Add your personal access token and account ID to pull time entries.</div>
                </div>
                <x-filament::button tag="a" href="{{ $this->profileUrl }}" color="gray" size="sm">
                    Open My Profile
                </x-filament::button>
            </div>
        </x-filament::section>
    @elseif ($connection['ok'])
        <x-filament::section>
            <div class="k-conn k-conn-ok">
                <x-filament::icon icon="heroicon-o-check-badge" class="k-conn-icon" />
                <div>
                    <div class="k-conn-title">Connected to Harvest</div>
                    <div class="k-conn-sub">{{ $connection['name'] }} &middot; {{ $connection['email'] }}</div>
                </div>
            </div>
        </x-filament::section>
    @else
        <x-filament::section>
            <div class="k-conn k-conn-bad">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="k-conn-icon" />
                <div>
                    <div class="k-conn-title">Harvest rejected the credentials</div>
                    <div class="k-conn-sub">{{ \Illuminate\Support\Str::limit($connection['error'], 140) }}</div>
                </div>
                <x-filament::button tag="a" href="{{ $this->profileUrl }}" color="gray" size="sm">
                    Fix in My Profile
                </x-filament::button>
            </div>
        </x-filament::section>
    @endif

    {{-- Period + fetch --}}
    <form wire:submit="fetch" class="space-y-6">
        {{ $this->form }}

        <x-filament::button type="submit" :disabled="$connection === null || ! $connection['ok']">
            <span wire:loading.remove wire:target="fetch">Pull time entries</span>
            <span wire:loading wire:target="fetch">Pulling from Harvest…</span>
        </x-filament::button>
    </form>

    {{-- Results --}}
    @if ($fetched)
        @php
            $start = \Carbon\Carbon::parse($periodStart);
            $end = \Carbon\Carbon::parse($periodEnd);
            $rate = $this->currentRate;
        @endphp

        <div class="k-summary">
            <div class="k-summary-card">
                <div class="k-summary-label">Period</div>
                <div class="k-summary-value">{{ $start->format('M j') }} – {{ $end->format('M j, Y') }}</div>
            </div>
            <div class="k-summary-card">
                <div class="k-summary-label">Total hours</div>
                <div class="k-summary-value">{{ rtrim(rtrim(number_format($this->totalHours, 2), '0'), '.') }}</div>
            </div>
            <div class="k-summary-card">
                <div class="k-summary-label">Days logged</div>
                <div class="k-summary-value">{{ $this->entriesByDay->count() }}</div>
            </div>
            <div class="k-summary-card k-summary-accent">
                <div class="k-summary-label">Invoice estimate</div>
                <div class="k-summary-value">
                    @if ($rate)
                        ${{ number_format($this->totalHours * $rate, 2) }}
                        <span class="k-summary-sub">@ ${{ rtrim(rtrim(number_format($rate, 2), '0'), '.') }}/h, before tax</span>
                    @else
                        <span class="k-summary-sub">No rate set</span>
                    @endif
                </div>
            </div>
        </div>

        @if (empty($entries))
            <x-filament::section>
                <div class="k-empty">
                    <x-filament::icon icon="heroicon-o-inbox" class="k-empty-icon" />
                    <div class="k-conn-title">No time entries in this period</div>
                    <div class="k-conn-sub">Harvest returned nothing between {{ $start->format('M j') }} and {{ $end->format('M j, Y') }}.</div>
                </div>
            </x-filament::section>
        @else
            <div class="k-two-col">
                <x-filament::section heading="Entries by day" class="k-entries">
                    <table class="k-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Project / task</th>
                                <th>Notes</th>
                                <th class="k-num">Hours</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->entriesByDay as $date => $dayEntries)
                                @php $dayTotal = round($dayEntries->sum('hours'), 2); @endphp
                                @foreach ($dayEntries as $i => $entry)
                                    <tr @class(['k-day-start' => $i === 0])>
                                        <td class="k-date">
                                            @if ($i === 0)
                                                <span class="k-dow">{{ \Carbon\Carbon::parse($date)->format('D') }}</span>
                                                {{ \Carbon\Carbon::parse($date)->format('M j') }}
                                            @endif
                                        </td>
                                        <td>
                                            <span class="k-project">{{ $entry['project'] ?: '—' }}</span>
                                            @if ($entry['task'])<span class="k-task">{{ $entry['task'] }}</span>@endif
                                        </td>
                                        <td class="k-notes">{{ $entry['notes'] ?: '' }}</td>
                                        <td class="k-num">{{ rtrim(rtrim(number_format($entry['hours'], 2), '0'), '.') }}</td>
                                    </tr>
                                @endforeach
                                @if ($dayEntries->count() > 1)
                                    <tr class="k-day-total">
                                        <td></td><td></td>
                                        <td class="k-notes">Day total</td>
                                        <td class="k-num">{{ rtrim(rtrim(number_format($dayTotal, 2), '0'), '.') }}</td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3">Total</td>
                                <td class="k-num">{{ rtrim(rtrim(number_format($this->totalHours, 2), '0'), '.') }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </x-filament::section>

                <div class="space-y-6">
                    <x-filament::section heading="By project">
                        <ul class="k-projects">
                            @foreach ($this->hoursByProject as $project => $hours)
                                <li>
                                    <span class="k-project-name">{{ $project ?: 'No project' }}</span>
                                    <span class="k-project-bar"><span style="width: {{ $this->totalHours > 0 ? round($hours / $this->totalHours * 100) : 0 }}%"></span></span>
                                    <span class="k-num">{{ rtrim(rtrim(number_format($hours, 2), '0'), '.') }}h</span>
                                </li>
                            @endforeach
                        </ul>
                    </x-filament::section>

                    <x-filament::section>
                        <div class="k-cta">
                            <div class="k-conn-title">Ready to invoice?</div>
                            <div class="k-conn-sub">Creates a draft with one line for these {{ rtrim(rtrim(number_format($this->totalHours, 2), '0'), '.') }} hours. You can edit it before sending.</div>
                            <x-filament::button wire:click="createInvoice" size="lg" class="k-cta-btn">
                                <span wire:loading.remove wire:target="createInvoice">Create invoice from these hours</span>
                                <span wire:loading wire:target="createInvoice">Creating…</span>
                            </x-filament::button>
                        </div>
                    </x-filament::section>
                </div>
            </div>
        @endif
    @endif
</x-filament-panels::page>
