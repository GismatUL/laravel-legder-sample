<x-mail::message>
# Invoice {{ $invoice->number }}

Dear {{ $invoice->customer->name }},

please find the details of your invoice below.

<x-mail::table>
| Description | Qty | Unit price | Amount |
|:--|--:|--:|--:|
@foreach ($invoice->lines as $line)
| {{ $line->description }} | {{ $line->quantity }} | {{ \App\Support\Money::of($line->unit_price)->format() }} | {{ \App\Support\Money::of($line->line_subtotal)->format() }} |
@endforeach
| | | **Net** | {{ $invoice->subtotal->format() }} |
| | | **VAT** | {{ $invoice->tax_total->format() }} |
| | | **Total** | **{{ $invoice->total->format() }}** |
</x-mail::table>

Issued on {{ $invoice->issue_date?->toDateString() }}, due on **{{ $invoice->due_date?->toDateString() }}**.
Please quote **{{ $invoice->number }}** as the payment reference, so the payment is matched automatically.

{{ config('app.name') }}
</x-mail::message>
