<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Vérification de votre compte ELChat</title>
    <meta name="x-apple-disable-message-reformatting">
</head>

<body style="margin:0;padding:0;background-color:#ff9100;font-family:Helvetica,Arial,sans-serif;">

<!-- BACKGROUND -->
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background-color:#ff9100;">
    <tr>
        <td align="center" style="padding:30px 10px;">

            <!-- CARD -->
            <table width="600" cellpadding="0" cellspacing="0" role="presentation"
                   style="background-color:#ffffff;border-radius:8px;">

                <!-- HEADER -->
                <tr>
                    <td align="center"
                        style="background-color:#fff3e0;padding:20px;border-radius:8px 8px 0 0;">
                        <h2 style="margin:0;color:#333333;font-size:24px;line-height:28px;">
                            Vérification de votre compte ELChat
                        </h2>
                    </td>
                </tr>

                <!-- BODY -->
                <tr>
                    <td style="padding:30px;color:#333333;font-size:15px;line-height:22px;">

                        <p style="margin:0 0 15px 0;">
                            <strong>Bonjour {{ \Illuminate\Support\Str::title($user->firstname) }},</strong>
                        </p>

                        <p style="margin:0 0 15px 0;">
                            Vous avez initié la création de votre compte sur <strong>ELChat</strong>,
                            l’assistant intelligent conçu pour vous accompagner à tout moment.
                            Il comprend vos questions, vous apporte des réponses claires et immédiates,
                            et vous guide rapidement vers les informations essentielles du site que vous consultez —
                            pour une navigation fluide, efficace et parfaitement adaptée à vos besoins.
                        </p>

                        <p style="margin:0 0 15px 0;">
                            Pour finaliser la sécurisation de votre compte, veuillez utiliser le code de vérification ci-dessous :
                        </p>

                        <!-- OTP CONTAINER -->
                        <table width="100%" cellpadding="0" cellspacing="0" role="presentation"
                               style="background-color:#fff8f1;border-radius:4px;">
                            <tr>
                                <td align="center" style="padding:20px;">

                                    <!-- OTP ROW -->
                                    <table cellpadding="0" cellspacing="0" role="presentation">
                                        <tr>
                                            @foreach (str_split($code) as $char)

                                                <td align="center"
                                                    style="
                                                        border:2px solid #ff9100;
                                                        background-color:#ffe0b2;
                                                        padding:12px 16px;
                                                        font-size:18px;
                                                        font-weight:bold;
                                                        color:#333333;
                                                        line-height:20px;
                                                        border-radius:4px;
                                                    ">
                                                    {{ $char }}
                                                </td>

                                                @if (!$loop->last)
                                                    <td width="8" style="font-size:0;line-height:0;">&nbsp;</td>
                                                @endif

                                            @endforeach
                                        </tr>
                                    </table>

                                </td>
                            </tr>
                        </table>

                        <p style="margin:20px 0 0 0;">
                            Ce code est valable pendant <strong>{{ $minutes }} minutes</strong>.
                        </p>

                        <p style="margin:15px 0 0 0;color:#999999;font-size:13px;line-height:18px;">
                            Pour des raisons de sécurité, ne partagez jamais ce code avec qui que ce soit.
                        </p>

                        <p style="margin:10px 0 0 0;color:#999999;font-size:13px;line-height:18px;">
                            Si vous n’êtes pas à l’origine de cette demande, vous pouvez ignorer cet email en toute sécurité.
                        </p>

                        <p style="margin:25px 0 0 0;color:#666666;">
                            Bienvenue dans une expérience digitale plus intelligente,<br>
                            <strong style="color:#333333;">L’équipe ELChat</strong>
                        </p>

                    </td>
                </tr>

                <!-- FOOTER -->
                <tr>
                    <td align="center"
                        style="padding:20px;border-top:1px solid #eeeeee;border-radius:0 0 8px 8px;">

                        <p style="margin:0;font-size:13px;color:#999999;line-height:18px;">
                            © 2026 ELChat. Tous droits réservés.
                        </p>

                        <!-- SOCIAL ICONS -->
                        <table cellpadding="0" cellspacing="0" role="presentation" style="margin-top:12px;">
                            <tr>
                                <td style="padding:0 6px;">
                                    <a href="https://linkedin.com" target="_blank">
                                        <img src="https://cdn-icons-png.flaticon.com/512/733/733561.png"
                                             width="20" height="20" alt="LinkedIn"
                                             style="display:block;border:0;">
                                    </a>
                                </td>
                                <td style="padding:0 6px;">
                                    <a href="https://twitter.com" target="_blank">
                                        <img src="https://cdn-icons-png.flaticon.com/512/733/733579.png"
                                             width="20" height="20" alt="Twitter"
                                             style="display:block;border:0;">
                                    </a>
                                </td>
                            </tr>
                        </table>

                    </td>
                </tr>

            </table>
            <!-- END CARD -->

        </td>
    </tr>
</table>

</body>
</html>