<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vérification de votre email — ELChat</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <style>
        body { margin:0; padding:0; background-color:#ff9100; font-family:Helvetica,Arial,sans-serif; }
        #el-page-container { display:flex; justify-content:center; padding:30px 10px; }
        #el-card-container { background-color:#ffffff; border-radius:8px; width:600px; overflow:hidden; }
        .el-card-header { background-color:#fff3e0; padding:20px; text-align:center; }
        .el-card-header h2 { margin:0; color:#333333; font-size:24px; }
        .el-card-body { padding:30px; color:#333333; font-size:15px; line-height:22px; }
        .el-card-body p { margin-bottom:15px; }
        /* Bloc code OTP */
        .el-code-block {
            margin: 28px 0;
            text-align: center;
        }
        .el-code-label {
            font-size: 12px;
            font-weight: bold;
            color: #999999;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 12px;
        }
        .el-code-value {
            display: inline-block;
            background-color: #fff3e0;
            border: 2px dashed #ff9100;
            border-radius: 8px;
            padding: 16px 40px;
            font-size: 38px;
            font-weight: 900;
            letter-spacing: 10px;
            color: #e65c00;
            font-family: 'Courier New', Courier, monospace;
        }
        .el-code-expiry {
            margin-top: 10px;
            font-size: 12px;
            color: #999999;
        }
        /* Alerte sécurité */
        .el-security-note {
            background-color: #fff8f0;
            border-left: 4px solid #ff9100;
            padding: 12px 16px;
            border-radius: 0 6px 6px 0;
            font-size: 13px;
            color: #666666;
            margin: 20px 0;
        }
        .el-security-note i { color: #ff9100; margin-right: 6px; }
        .el-best-regards { margin-top:25px; }
        .el-card-footer { padding:20px; border-top:1px solid #eeeeee; text-align:center; font-size:13px; color:#999999; }
        .el-card-footer .el-container a { margin:0 6px; color:#999999; text-decoration:none; }
    </style>
</head>
<body>
<div id="el-page-container">
    <div id="el-card-container">

        <div class="el-card-header">
            <h2>Vérifiez votre adresse email ✉️</h2>
        </div>

        <div class="el-card-body">
            <p><strong>Bonjour {{ \Illuminate\Support\Str::title($user->firstname) }},</strong></p>

            <p>
                Merci de vous être inscrit sur <strong>ELChat</strong>.
                Pour finaliser la création de votre compte, veuillez entrer le code de vérification ci-dessous
                dans la page de confirmation.
            </p>

            <div class="el-code-block">
                <div class="el-code-label">Votre code de vérification</div>
                <div class="el-code-value">{{ $code }}</div>
                <div class="el-code-expiry">
                    <i class="fa-regular fa-clock"></i>
                    Ce code expire dans <strong>5 minutes</strong>
                </div>
            </div>

            <p>
                Entrez ce code sur la page de vérification pour activer votre compte et accéder à votre espace ELChat.
            </p>

            <div class="el-security-note">
                <i class="fa-solid fa-shield-halved"></i>
                <strong>Sécurité :</strong> Si vous n'êtes pas à l'origine de cette demande, ignorez cet email.
                Votre compte ne sera pas créé sans cette vérification.
                Ne partagez jamais ce code avec quelqu'un d'autre.
            </div>

            <h3 class="el-best-regards">Cordialement,</h3>
            <h3>L'équipe ELChat</h3>
        </div>

        <div class="el-card-footer">
            <p>&copy; {{ date('Y') }} ELChat. Tous droits réservés.</p>
            <div class="el-container">
                <a href="https://www.linkedin.com/"><i class="fa-brands fa-linkedin-in"></i></a>
                <a href="https://twitter.com/"><i class="fa-brands fa-twitter"></i></a>
            </div>
        </div>

    </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/js/all.min.js"></script>
</body>
</html>
