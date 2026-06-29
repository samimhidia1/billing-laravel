<!DOCTYPE html>
<html lang="en">
<body style="font-family: -apple-system, Segoe UI, Roboto, sans-serif; color: #1f2937;">
    <h2>Overdue Invoice Reminder</h2>
    <p>Dear {{ $invoice->customer->name }},</p>
    <p>
        Invoice <strong>{{ $invoice->invoice_number }}</strong> was due on
        <strong>{{ $invoice->due_date->format('Y-m-d') }}</strong> and is now overdue.
    </p>
    <p>
        Amount due:
        <strong>{{ number_format((float) $invoice->total_amount + (float) $invoice->late_fee_amount, 2) }} {{ $invoice->currency }}</strong>
        @if ((float) $invoice->late_fee_amount > 0)
            (includes {{ number_format((float) $invoice->late_fee_amount, 2) }} {{ $invoice->currency }} late fee)
        @endif
    </p>
    <p>Please settle this invoice as soon as possible to avoid additional late fees.</p>
    <p>Thank you,<br>{{ config('app.name') }}</p>
</body>
</html>
