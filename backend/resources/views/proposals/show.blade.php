<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $proposal->title ?: 'Proposal' }}</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; background: #f5f7fa; color: #1f2937; }
        .container { max-width: 900px; margin: 24px auto; background: #ffffff; border-radius: 12px; padding: 24px; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08); }
        .header { margin-bottom: 16px; }
        .meta { color: #6b7280; font-size: 14px; margin-bottom: 20px; }
        .actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 20px; }
        .btn { border: none; border-radius: 8px; padding: 10px 14px; font-size: 14px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-primary { background: #1d4ed8; color: #fff; }
        .btn-secondary { background: #e5e7eb; color: #111827; }
        .status { display: inline-block; margin-top: 10px; padding: 4px 10px; border-radius: 999px; background: #e0f2fe; color: #0c4a6e; font-size: 12px; }
        .accepted { background: #dcfce7; color: #14532d; }
        .body { border-top: 1px solid #e5e7eb; padding-top: 16px; line-height: 1.55; }
        .flash { margin-top: 12px; padding: 10px; border-radius: 8px; background: #dcfce7; color: #14532d; font-size: 14px; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>{{ $proposal->title ?: 'Proposal' }}</h1>
        <div class="meta">
            @if($lead)
                <div>For: {{ trim(($lead->first_name ?? '').' '.($lead->last_name ?? '')) ?: ($lead->email ?? 'Lead') }}</div>
            @endif
            <div>Version: {{ $proposal->version_no }}</div>
            @if($proposal->quote_amount)
                <div>Amount: {{ $proposal->currency }} {{ number_format((float) $proposal->quote_amount, 2) }}</div>
            @endif
            @if($proposal->service)
                <div>Service: {{ $proposal->service }}</div>
            @endif
        </div>

        <span class="status {{ $proposal->status === 'accepted' ? 'accepted' : '' }}">{{ strtoupper((string) $proposal->status) }}</span>

        @if(session('proposal_accepted'))
            <div class="flash">Proposal accepted successfully.</div>
        @endif
    </div>

    <div class="body">{!! $proposal->body_html !!}</div>

    <div class="actions">
        <a class="btn btn-secondary" href="{{ $pdfUrl }}">Download PDF</a>

        @if($proposal->status !== 'accepted')
            <form method="POST" action="{{ $acceptUrl }}">
                @csrf
                <input type="hidden" name="accepted_by" value="{{ trim(($lead->first_name ?? '').' '.($lead->last_name ?? '')) }}">
                <button class="btn btn-primary" type="submit">Accept Proposal</button>
            </form>
        @endif
    </div>
</div>
</body>
</html>
