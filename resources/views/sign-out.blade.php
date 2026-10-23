<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex">
    <title>Signing out</title>
</head>
<body>
<form method="POST" action="{{ $action }}">
    @foreach ($fields as $name => $value)
        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
    @endforeach
    <button type="submit">Continue signing out</button>
</form>
<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">document.forms[0].submit();</script>
</body>
</html>
