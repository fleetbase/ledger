<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Refund available for invoice {{ $invoiceNumber }}</title>
</head>
<body style="margin:0; padding:0; background:#eef2f7; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; color:#1f2937;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef2f7; padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;">
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
                                        <div style="font-size:12px; line-height:16px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:#64748b;">Refund available</div>
                                        <h1 style="margin:8px 0 8px; font-size:24px; line-height:32px; color:#111827;">{{ $refundAmount }}</h1>
                                        <p style="margin:0; font-size:15px; line-height:24px; color:#475569;">
                                            @if ($customerName)
                                                Hi {{ $customerName }},
                                            @else
                                                Hello,
                                            @endif
                                            {{ $companyName }} issued a refund for invoice <strong style="color:#1f2937;">{{ $invoiceNumber }}</strong>.
                                            Open the refund link with your GNU Taler wallet to accept it.
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:0 32px 22px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid #e5e7eb; border-bottom:1px solid #e5e7eb;">
                                            @if ($orderLabel)
                                                <tr>
                                                    <td style="padding:14px 0; font-size:13px; color:#64748b;">Order</td>
                                                    <td align="right" style="padding:14px 0; font-size:13px; font-weight:600; color:#1f2937;">{{ $orderLabel }}</td>
                                                </tr>
                                            @endif
                                            @if ($issuedAt)
                                                <tr>
                                                    <td style="padding:14px 0; font-size:13px; color:#64748b;">Issued</td>
                                                    <td align="right" style="padding:14px 0; font-size:13px; font-weight:600; color:#1f2937;">{{ $issuedAt }}</td>
                                                </tr>
                                            @endif
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td align="center" style="padding:0 32px 24px;">
                                        <a href="{{ $refundUrl }}" style="display:inline-block; background:#111827; color:#ffffff; text-decoration:none; border-radius:7px; padding:12px 20px; font-size:14px; line-height:20px; font-weight:700;">Open refund</a>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:0 32px 30px;">
                                        <div style="font-size:12px; line-height:18px; color:#64748b;">If the button does not open, copy this refund page URL:</div>
                                        <div style="margin-top:8px; padding:12px; border-radius:8px; background:#f8fafc; border:1px solid #e2e8f0; font-size:12px; line-height:18px; color:#334155; word-break:break-all;">{{ $refundUrl }}</div>
                                        <div style="margin-top:12px; font-size:12px; line-height:18px; color:#64748b;">Advanced wallet URI:</div>
                                        <div style="margin-top:8px; padding:12px; border-radius:8px; background:#f8fafc; border:1px solid #e2e8f0; font-size:12px; line-height:18px; color:#334155; word-break:break-all;">{{ $refundUri }}</div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding:18px 10px 0; font-size:12px; line-height:18px; color:#64748b;">
                            This refund notice was sent by {{ $companyName }}.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
