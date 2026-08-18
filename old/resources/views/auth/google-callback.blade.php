<!DOCTYPE html>
<html>
<head>
    <title>Authentification Google</title>

    <script>
        const data = @json([
            'ok' => $ok ?? true,
            'message' => $message ?? 'Connecté',
            'user' => $user
         ]);

        // Poste le message au parent
        window.opener.postMessage(data, 'https://elchat-widget.promogifts.ma/sign-up');
        window.close();
    </script>
</head>
<body>
</body>
</html>