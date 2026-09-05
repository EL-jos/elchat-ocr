<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $ok ? 'Connexion réussie' : 'Connexion impossible' }} — ELChat</title>
    <style>
        * { box-sizing: border-box; }
        body {
            min-height: 100vh;
            margin: 0;
            display: grid;
            place-items: center;
            padding: 24px;
            background: linear-gradient(145deg, #eef2ff, #ffffff 55%, #f5f3ff);
            color: #111827;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        main {
            width: min(440px, 100%);
            padding: 32px;
            text-align: center;
            border: 1px solid rgba(99, 102, 241, .16);
            border-radius: 22px;
            background: rgba(255, 255, 255, .86);
            box-shadow: 0 24px 65px rgba(79, 70, 229, .14);
            backdrop-filter: blur(18px);
        }
        .status {
            width: 52px;
            height: 52px;
            margin: 0 auto 18px;
            display: grid;
            place-items: center;
            border-radius: 50%;
            background: {{ $ok ? '#dcfce7' : '#fee2e2' }};
            color: {{ $ok ? '#15803d' : '#b91c1c' }};
            font-size: 26px;
            font-weight: 800;
        }
        h1 { margin: 0 0 10px; font-size: 21px; }
        p { margin: 0; color: #6b7280; font-size: 14px; line-height: 1.6; }
    </style>
</head>
<body>
<main>
    <div class="status" aria-hidden="true">{{ $ok ? '✓' : '!' }}</div>
    <h1>{{ $ok ? 'Connexion réussie' : 'Connexion interrompue' }}</h1>
    <p>{{ $message }}</p>
</main>
<script>
    (() => {
        const payload = {
            type: 'mcp_oauth',
            status: @json($ok ? 'success' : 'error'),
            message: @json($message),
            data: {
                slug: @json($slug),
                site_id: @json($siteId),
            },
        };

        if (window.opener && !window.opener.closed) {
            window.opener.postMessage(payload, @json($targetOrigin));
            window.setTimeout(() => window.close(), 350);
            return;
        }

        window.location.replace(@json($fallbackUrl));
    })();
</script>
</body>
</html>
