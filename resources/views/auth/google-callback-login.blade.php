<!DOCTYPE html>
<html>
<head>
    <title>Authentification Google</title>

    <script>
        const data = @json($data);

        // Poste le message au parent
        window.opener.postMessage(data, 'https://elchat.io/sign-in');
        window.close();
    </script>
</head>
<body></body>
</html>