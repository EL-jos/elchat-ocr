<!-- resources/views/social/email/popup.blade.php -->
<!DOCTYPE html>
<html>
<head><title>ELChat Email Auth</title></head>
<body>
<script>
    (function () {
        const payload = {
            type:    "email_oauth",
            status:  @json($ok ? 'success' : 'error'),
            message: @json($message),
            data:    @json($data)
        };

        console.log('ELChat Payload:', payload);

        if (window.opener) {
            window.opener.postMessage(payload, "{{ $origin }}");
            setTimeout(() => window.close(), 10000);
        }
    })();
</script>
<p>{{ $message }}</p>
</body>
</html>
