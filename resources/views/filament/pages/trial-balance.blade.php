{{-- Inline styles: Filament's prebuilt CSS doesn't include arbitrary Tailwind classes without a custom theme. --}}
<x-filament-panels::page>
    @php($report = $this->getReport())

    <div style="max-width: 16rem">
        {{ $this->form }}
    </div>

    <x-filament::section>
        <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem">
            <thead>
                <tr style="text-align: left; border-bottom: 1px solid rgba(128, 128, 128, 0.3)">
                    <th style="padding: 0.5rem 0">Code</th>
                    <th style="padding: 0.5rem 0">Account</th>
                    <th style="padding: 0.5rem 0">Type</th>
                    <th style="padding: 0.5rem 0; text-align: right">Debit</th>
                    <th style="padding: 0.5rem 0; text-align: right">Credit</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($report->rows as $row)
                    <tr style="border-bottom: 1px solid rgba(128, 128, 128, 0.15)">
                        <td style="padding: 0.5rem 0">{{ $row['code'] }}</td>
                        <td style="padding: 0.5rem 0">{{ $row['name'] }}</td>
                        <td style="padding: 0.5rem 0">{{ $row['type']->getLabel() }}</td>
                        <td style="padding: 0.5rem 0; text-align: right; font-variant-numeric: tabular-nums">{{ $row['debit']->isZero() ? '' : $row['debit']->format() }}</td>
                        <td style="padding: 0.5rem 0; text-align: right; font-variant-numeric: tabular-nums">{{ $row['credit']->isZero() ? '' : $row['credit']->format() }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" style="padding: 1.5rem 0; text-align: center; opacity: 0.6">No postings up to this date.</td>
                    </tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr style="font-weight: 600">
                    <td colspan="3" style="padding: 0.5rem 0">Total</td>
                    <td style="padding: 0.5rem 0; text-align: right; font-variant-numeric: tabular-nums">{{ $report->totalDebit->format() }}</td>
                    <td style="padding: 0.5rem 0; text-align: right; font-variant-numeric: tabular-nums">{{ $report->totalCredit->format() }}</td>
                </tr>
            </tfoot>
        </table>
    </x-filament::section>

    <div>
        @if ($report->isBalanced())
            <x-filament::badge color="success" icon="heroicon-o-check-circle">Debits equal credits</x-filament::badge>
        @else
            <x-filament::badge color="danger" icon="heroicon-o-exclamation-triangle">Out of balance</x-filament::badge>
        @endif
    </div>
</x-filament-panels::page>
