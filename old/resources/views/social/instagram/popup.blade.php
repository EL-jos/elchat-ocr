<!DOCTYPE html>
<html>
<head>
    <title>ELChat Instagram Auth</title>
</head>
<body>

<script>
    (function () {

        const payload = {
            type: "instagram_oauth",
            status: @json($ok ? 'success' : 'error'),
            message: @json($message),
            data: @json($data) // 🔥 IMPORTANT
        };

        console.log('ELChat Payload:', payload);

        if (window.opener) {

            window.opener.postMessage(
                payload,
                "{{ $origin }}"
            );

            setTimeout(() => window.close(), 300);
        }
    })();
</script>

<p>{{ $message }}</p>

</body>
</html>
