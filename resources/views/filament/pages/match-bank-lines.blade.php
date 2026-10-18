{{-- Inline styles: Filament's prebuilt CSS doesn't include arbitrary Tailwind classes without a custom theme. --}}
<x-filament-panels::page>
    <div
        x-data
        x-on:keydown.escape.window="$wire.clearSelection()"
        style="display: grid; grid-template-columns: repeat(auto-fit, minmax(22rem, 1fr)); gap: 1.5rem; align-items: start"
    >
        <x-filament::section heading="Unmatched bank lines" description="Money received that the automatic run couldn't match.">
            <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem">
                <tbody>
                    @forelse ($this->transactions as $transaction)
                        <tr
                            wire:key="tx-{{ $transaction->id }}"
                            wire:click="select({{ $transaction->id }})"
                            style="cursor: pointer; border-bottom: 1px solid rgba(128, 128, 128, 0.15); {{ $transaction->id === $selectedId ? 'background: rgba(16, 185, 129, 0.12)' : '' }}"
                        >
                            <td style="padding: 0.5rem">{{ $transaction->booked_on->toDateString() }}</td>
                            <td style="padding: 0.5rem; word-break: break-word">{{ $transaction->reference }}</td>
                            <td style="padding: 0.5rem; text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap">{{ $transaction->amount->format() }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td style="padding: 1.5rem 0; text-align: center; opacity: 0.6">Everything is reconciled.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>

        <x-filament::section heading="Payments with the same amount">
            @if ($this->selected === null)
                <p style="opacity: 0.6; font-size: 0.875rem">Select a bank line on the left.</p>
            @else
                {{-- Client-side filter: no request per keystroke. Reset when another line is selected. --}}
                <div x-data="{ q: '' }" wire:key="candidates-{{ $selectedId }}">
                    <p style="font-size: 0.875rem; margin-bottom: 0.75rem">
                        {{ $this->selected->amount->format() }} · {{ $this->selected->reference }}
                        <span style="opacity: 0.6">(Esc to clear)</span>
                    </p>

                    <x-filament::input.wrapper style="margin-bottom: 0.75rem">
                        <x-filament::input type="search" x-model="q" placeholder="Filter by invoice, customer or reference" />
                    </x-filament::input.wrapper>

                    <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem">
                        <tbody>
                            @forelse ($this->candidates as $payment)
                                <tr
                                    wire:key="payment-{{ $payment->id }}"
                                    data-search="{{ strtolower("{$payment->invoice->number} {$payment->invoice->customer->name} {$payment->reference}") }}"
                                    x-show="q === '' || $el.dataset.search.includes(q.toLowerCase())"
                                    style="border-bottom: 1px solid rgba(128, 128, 128, 0.15)"
                                >
                                    <td style="padding: 0.5rem">{{ $payment->paid_on->toDateString() }}</td>
                                    <td style="padding: 0.5rem">
                                        {{ $payment->invoice->number }}<br>
                                        <span style="opacity: 0.7">{{ $payment->invoice->customer->name }}</span>
                                    </td>
                                    <td style="padding: 0.5rem; opacity: 0.7">{{ $payment->reference ?? '-' }}</td>
                                    <td style="padding: 0.5rem; text-align: right">
                                        <x-filament::button
                                            size="sm"
                                            x-on:click="if (confirm('Match this bank line to {{ $payment->invoice->number }}?')) $wire.match({{ $payment->id }})"
                                        >
                                            Match
                                        </x-filament::button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td style="padding: 1.5rem 0; text-align: center; opacity: 0.6">
                                        No unmatched payment of {{ $this->selected->amount->format() }}. Record the payment on its invoice first.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
