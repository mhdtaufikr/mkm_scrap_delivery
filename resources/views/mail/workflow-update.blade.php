<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Surat Jalan MKM</title>
</head>
<body style="margin:0; padding:0; background:#ffffff; color:#222222; font-family:Arial, Helvetica, sans-serif; font-size:16px; line-height:1.38;">
<div style="max-width:860px; margin:0 auto; padding:34px 28px 42px;">
    <p style="margin:0 0 8px;">Dear {{ $recipient->name }},</p>

    @if ($trial)
        <p style="margin:0 0 8px; color:#c0392b;">Trial mode is active. This email was redirected to the trial recipient list.</p>
    @endif

    <p style="margin:0 0 18px;">{{ $requiresApproval ? 'The below application awaits your approval on the Surat Jalan system. Please confirm from below.' : 'The below application has an update on the Surat Jalan system. This email is for your information only; no approval action is required.' }}</p>

    <div style="font-family:Consolas, 'Courier New', monospace; white-space:pre-line; margin:0 0 14px;">------------------------------
&lt;&gt;Content Overview&lt;&gt;
------------------------------</div>

    <p style="margin:0;"><strong>Application Type:</strong></p>
    <p style="margin:0 0 5px;">{{ $document?->type === 'DISPOSAL' ? 'DISPOSAL' : 'SCRAP' }}</p>
    <p style="margin:0;"><strong>Document Number:</strong></p>
    <p style="margin:0 0 5px;">{{ $document?->number ?? '-' }}</p>
    <p style="margin:0;"><strong>Applicant:</strong></p>
    <p style="margin:0 0 5px;">{{ $document?->creator?->name ?? '-' }}</p>
    <p style="margin:0;"><strong>Date:</strong></p>
    <p style="margin:0 0 5px;">{{ $document?->created_at?->format('Y-m-d H:i:s') ?? '-' }}</p>
    <p style="margin:0;"><strong>Current Step:</strong></p>
    <p style="margin:0 0 18px;">{{ $step }}</p>

    @if ($reason)
        <p style="margin:0 0 18px;"><strong>Note:</strong> {{ $reason }}</p>
    @endif

    <div style="font-family:Consolas, 'Courier New', monospace; white-space:pre-line; margin:0 0 8px;">------------------------------</div>
    <p style="margin:0 0 6px;">Application URL below. Please click to open in new window.</p>
    <p style="margin:0 0 18px;"><a href="{{ $url }}" style="color:#0563c1; text-decoration:underline; word-break:break-all;">{{ $url }}</a></p>

    <div style="font-family:Consolas, 'Courier New', monospace; white-space:pre-line; margin:0 0 14px;">------------------------------
&lt;&gt;Application Detail&lt;&gt;
------------------------------</div>

    <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse; width:100%; max-width:700px; margin:0 0 20px;">
        <tr><td style="padding:2px 16px 2px 0; width:38%; vertical-align:top;">Document Number:</td><td style="padding:2px 0; vertical-align:top;">{{ $document?->number ?? '-' }}</td></tr>
        <tr><td style="padding:2px 16px 2px 0; vertical-align:top;">Requester:</td><td style="padding:2px 0; vertical-align:top;">{{ $document?->creator?->name ?? '-' }}</td></tr>
        <tr><td style="padding:2px 16px 2px 0; vertical-align:top;">Document Date:</td><td style="padding:2px 0; vertical-align:top;">{{ $document?->created_at?->format('Y-m-d') ?? '-' }}</td></tr>
        <tr><td style="padding:2px 16px 2px 0; vertical-align:top;">Company / Buyer:</td><td style="padding:2px 0; vertical-align:top;">{{ $document?->company ?? '-' }}</td></tr>
        <tr><td style="padding:2px 16px 2px 0; vertical-align:top;">Driver:</td><td style="padding:2px 0; vertical-align:top;">{{ $document?->driver_name ?? '-' }}</td></tr>
        <tr><td style="padding:2px 16px 2px 0; vertical-align:top;">Vehicle / Plate Number:</td><td style="padding:2px 0; vertical-align:top;">{{ trim(($document?->vehicle ?? '-') . ' / ' . ($document?->plate_number ?? '-')) }}</td></tr>
        <tr><td style="padding:2px 16px 2px 0; vertical-align:top;">Destination:</td><td style="padding:2px 0; vertical-align:top;">{{ $document?->destination ?? '-' }}</td></tr>
        <tr><td style="padding:2px 16px 2px 0; vertical-align:top;">Goods:</td><td style="padding:2px 0; vertical-align:top;">{{ $document?->goods_description ?? '-' }}</td></tr>
        <tr><td style="padding:2px 16px 2px 0; vertical-align:top;">Current Step:</td><td style="padding:2px 0; vertical-align:top;">{{ $step }}</td></tr>
    </table>

    <div style="font-family:Consolas, 'Courier New', monospace; white-space:pre-line; margin:0 0 10px;">------------------------------
&lt;Caution&gt;</div>
    <p style="margin:0;">This is an auto-generated message sent from the Surat Jalan MKM system address.<br>Replying to this message will address the sender when their email is available.</p>
</div>
</body>
</html>
