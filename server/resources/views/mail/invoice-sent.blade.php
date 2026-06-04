<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoice {{ $invoiceNumber }}</title>
</head>
<body style="margin:0; padding:0; background:#eef2f7; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; color:#1f2937;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef2f7; padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:680px;">
                    <tr>
                        <td align="center" style="padding:0 0 22px;">
                            @if ($companyLogoUrl)
                                <img src="{{ $companyLogoUrl }}" alt="{{ $companyName }}" style="max-height:48px; max-width:180px; display:block;">
                            @else
                                <div style="font-size:22px; line-height:28px; font-weight:700; color:#111827;">{{ $companyName }}</div>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#ffffff; border:1px solid #dce3ec; border-radius:14px; overflow:hidden;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="padding:30px 32px 18px;">
                                        <div style="font-size:12px; line-height:16px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:#64748b;">Invoice</div>
                                        <h1 style="margin:8px 0 8px; font-size:26px; line-height:34px; color:#111827;">{{ $invoiceNumber }}</h1>
                                        <p style="margin:0; font-size:15px; line-height:24px; color:#475569;">
                                            {{ $companyName }} sent you an invoice@if ($orderLabel) for order <strong style="color:#1f2937;">{{ $orderLabel }}</strong>@endif.
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:0 32px 24px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid #e5e7eb; border-bottom:1px solid #e5e7eb;">
                                            <tr>
                                                <td width="50%" valign="top" style="padding:18px 18px 18px 0;">
                                                    <div style="font-size:11px; line-height:15px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#94a3b8;">Bill To</div>
                                                    <div style="margin-top:6px; font-size:14px; line-height:22px; font-weight:700; color:#111827;">{{ $customerName ?: 'Customer' }}</div>
                                                    @if ($customerEmail)
                                                        <div style="font-size:13px; line-height:20px; color:#64748b;">{{ $customerEmail }}</div>
                                                    @endif
                                                </td>
                                                <td width="50%" valign="top" style="padding:18px 0 18px 18px;">
                                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                                        @if ($invoiceDate)
                                                            <tr>
                                                                <td style="padding:0 0 6px; font-size:13px; color:#64748b;">Invoice date</td>
                                                                <td align="right" style="padding:0 0 6px; font-size:13px; font-weight:600; color:#1f2937;">{{ $invoiceDate }}</td>
                                                            </tr>
                                                        @endif
                                                        @if ($dueDate)
                                                            <tr>
                                                                <td style="padding:0 0 6px; font-size:13px; color:#64748b;">Due date</td>
                                                                <td align="right" style="padding:0 0 6px; font-size:13px; font-weight:600; color:#1f2937;">{{ $dueDate }}</td>
                                                            </tr>
                                                        @endif
                                                        @if ($orderLabel)
                                                            <tr>
                                                                <td style="padding:0; font-size:13px; color:#64748b;">Order</td>
                                                                <td align="right" style="padding:0; font-size:13px; font-weight:600; color:#1f2937;">{{ $orderLabel }}</td>
                                                            </tr>
                                                        @endif
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:0 32px 8px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                                            <thead>
                                                <tr>
                                                    <th align="left" style="padding:0 0 10px; border-bottom:1px solid #dbe3ed; font-size:11px; line-height:15px; text-transform:uppercase; letter-spacing:.06em; color:#64748b;">Description</th>
                                                    <th align="right" style="padding:0 0 10px 12px; border-bottom:1px solid #dbe3ed; font-size:11px; line-height:15px; text-transform:uppercase; letter-spacing:.06em; color:#64748b;">Qty</th>
                                                    <th align="right" style="padding:0 0 10px 12px; border-bottom:1px solid #dbe3ed; font-size:11px; line-height:15px; text-transform:uppercase; letter-spacing:.06em; color:#64748b;">Unit Price</th>
                                                    <th align="right" style="padding:0 0 10px 12px; border-bottom:1px solid #dbe3ed; font-size:11px; line-height:15px; text-transform:uppercase; letter-spacing:.06em; color:#64748b;">Amount</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @forelse ($items as $item)
                                                    <tr>
                                                        <td style="padding:12px 0; border-bottom:1px solid #edf1f5; font-size:14px; line-height:21px; color:#334155;">{{ $item['description'] }}</td>
                                                        <td align="right" style="padding:12px 0 12px 12px; border-bottom:1px solid #edf1f5; font-size:14px; line-height:21px; color:#475569;">{{ $item['quantity'] }}</td>
                                                        <td align="right" style="padding:12px 0 12px 12px; border-bottom:1px solid #edf1f5; font-size:14px; line-height:21px; color:#475569; white-space:nowrap;">{{ $item['unitPrice'] }}</td>
                                                        <td align="right" style="padding:12px 0 12px 12px; border-bottom:1px solid #edf1f5; font-size:14px; line-height:21px; font-weight:700; color:#111827; white-space:nowrap;">{{ $item['amount'] }}</td>
                                                    </tr>
                                                @empty
                                                    <tr>
                                                        <td colspan="4" style="padding:16px 0; border-bottom:1px solid #edf1f5; font-size:14px; line-height:21px; color:#64748b;">No line items were recorded.</td>
                                                    </tr>
                                                @endforelse
                                            </tbody>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:0 32px 26px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td width="45%"></td>
                                                <td width="55%">
                                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                                        <tr>
                                                            <td style="padding:8px 0; font-size:14px; color:#64748b;">Subtotal</td>
                                                            <td align="right" style="padding:8px 0; font-size:14px; color:#1f2937; white-space:nowrap;">{{ $subtotal }}</td>
                                                        </tr>
                                                        <tr>
                                                            <td style="padding:8px 0; font-size:14px; color:#64748b;">Tax</td>
                                                            <td align="right" style="padding:8px 0; font-size:14px; color:#1f2937; white-space:nowrap;">{{ $tax }}</td>
                                                        </tr>
                                                        <tr>
                                                            <td style="padding:8px 0; border-top:1px solid #dbe3ed; font-size:14px; font-weight:700; color:#111827;">Total</td>
                                                            <td align="right" style="padding:8px 0; border-top:1px solid #dbe3ed; font-size:14px; font-weight:700; color:#111827; white-space:nowrap;">{{ $total }}</td>
                                                        </tr>
                                                        @if ($hasAmountPaid)
                                                            <tr>
                                                                <td style="padding:8px 0; font-size:14px; color:#15803d;">Amount paid</td>
                                                                <td align="right" style="padding:8px 0; font-size:14px; color:#15803d; white-space:nowrap;">{{ $amountPaid }}</td>
                                                            </tr>
                                                        @endif
                                                        <tr>
                                                            <td style="padding:10px 0 0; border-top:1px solid #dbe3ed; font-size:16px; font-weight:800; color:#111827;">Balance due</td>
                                                            <td align="right" style="padding:10px 0 0; border-top:1px solid #dbe3ed; font-size:16px; font-weight:800; color:#111827; white-space:nowrap;">{{ $balance }}</td>
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td align="center" style="padding:0 32px 34px;">
                                        <a href="{{ $invoiceUrl }}" style="display:inline-block; background:#111827; color:#ffffff; text-decoration:none; border-radius:7px; padding:12px 20px; font-size:14px; line-height:20px; font-weight:700;">View Invoice</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding:18px 10px 0; font-size:12px; line-height:18px; color:#64748b;">
                            This invoice was sent by {{ $companyName }}.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
