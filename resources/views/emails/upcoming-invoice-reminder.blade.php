<!DOCTYPE html>
<html lang="en">
<body style="font-family: -apple-system, Segoe UI, Roboto, sans-serif; color: #1f2937;">
    <h2>Upcoming Invoice Due</h2>
    <p>Dear {{ $invoice->customer->name }},</p>
    <p>
        A friendly reminder that invoice <strong>{{ $invoice->invoice_number }}</strong> is due on
        <strong>{{ $invoice->due_date->format('Y-m-d') }}</strong>.
    </p>
    <p>
        Amount due:
        <strong>{{ number_format((float) $invoice->total_amount, 2) }} {{ $invoice->currency }}</strong>
    </p>
    <p>Please plan to pay by the due date to avoid late fees.</p>
    <p>Thank you,<br>{{ config('app.name') }}</p>
</body>
</html>
