<!DOCTYPE html>
<html>
<head>
    <title>Authentification Google</title>
    <script>
        window.opener.postMessage({
            type: 'GOOGLE_AUTH_SUCCESS',
            token: @json($token),
            message: @json($message)
        }, '{{ env('WIDGET_ORIGIN') }}'); // Ex: https://elchat-widget.promogifts.ma
        window.close();
    </script>
</head>
<body>
<p>{{ $message }}</p>
</body>
</html>