<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Unsubscribe Confirmed</title>
    <style>
        body {
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            background: #f4f6f8;
            color: #102027;
        }
        .card {
            max-width: 560px;
            margin: 64px auto;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 12px 32px rgba(16, 32, 39, 0.12);
            padding: 28px 30px;
        }
        h1 {
            margin-top: 0;
            font-size: 1.5rem;
        }
        p {
            margin: 10px 0;
            line-height: 1.55;
        }
        .muted {
            color: #546e7a;
            font-size: 0.95rem;
        }
    </style>
</head>
<body>
    <main class="card">
        <h1>Unsubscribe Confirmed</h1>
        <p>Your {{ $channel }} preference has been updated.</p>
        <p class="muted">Value: {{ $value }}</p>
        <p class="muted">You can close this page.</p>
    </main>
</body>
</html>
