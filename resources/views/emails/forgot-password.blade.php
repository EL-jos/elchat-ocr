<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Réinitialisation de mot de passe — ELChat</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <style>
        body { margin:0; padding:0; background-color:#ff9100; font-family:Helvetica,Arial,sans-serif; }
        #el-page-container { display:flex; justify-content:center; padding:30px 10px; }
        #el-card-container { background-color:#ffffff; border-radius:8px; width:600px; overflow:hidden; }
        .el-card-header { background-color:#fff3e0; padding:20px; text-align:center; }
        .el-card-header h2 { margin:0; color:#333333; font-size:24px; }
        .el-card-body { padding:30px; color:#333333; font-size:15px; line-height:22px; }
        .el-card-body p { margin-bottom:15px; }
        .el-code-block { margin:28px 0; text-align:center; }
        .el-code-label { font-size:12px; font-weight:bold; color:#999999; text-transform:uppercase; letter-spacing:2px; margin-bottom:12px; }
        .el-code-value {
            display:inline-block;
            background-color:#fff3e0;
            border:2px dashed #ff9100;
            border-radius:8px;
            padding:16px 40px;
            font-size:38px;
            font-weight:900;
            letter-spacing:10px;
            color:#e65c00;
            font-family:'Courier New',Courier,monospace;
        }
        .el-code-expiry { margin-top:10px; font-size:12px; color:#999999; }
        .el-steps { margin:20px 0; }
        .el-step-item { display:flex; align-items:flex-start; margin-bottom:12px; gap:12px; }
        .el-step-number {
            min-width:26px; height:26px;
            background-color:#ff9100; color:#ffffff;
            border-radius:50%; display:flex;
            justify-content:center; align-items:center;
            font-size:13px; font-weight:bold; flex-shrink:0;
        }
        .el-step-text { font-size:14px; color:#555555; padding-top:3px; line-height:1.5; }
        .el-security-note {
            background-color:#fff8f0; border-left:4px solid #ff9100;
            padding:12px 16px; border-radius:0 6px 6px 0;
            font-size:13px; color:#666666; margin:20px 0;
        }
        .el-security-note i { color:#ff9100; margin-right:6px; }
        .el-best-regards { margin-top:25px; }
        .el-card-footer { padding:20px; border-top:1px solid #eeeeee; text-align:center; font-size:13px; color:#999999; }
        .el-card-footer .el-container a { margin:0 6px; color:#999999; text-decoration:none; }
    </style>
</head>
<body>
<div id="el-page-container">
    <div id="el-card-container">

        <div class="el-card-header">
            <h2>Réinitialisation de mot de passe 🔐</h2>
        </div>

        <div class="el-card-body">
            <p><strong>Bonjour {{ \Illuminate\Support\Str::title($user->firstname) }},</strong></p>

            <p>
                Nous avons reçu une demande de réinitialisation du mot de passe associé à votre compte <strong>ELChat</strong>
                (<em>{{ $user->email }}</em>).
                Utilisez le code ci-dessous pour créer un nouveau mot de passe.
            </p>

            <div class="el-code-block">
                <div class="el-code-label">Votre code de réinitialisation</div>
                <div class="el-code-value">{{ $code }}</div>
                <div class="el-code-expiry">
                    <i class="fa-regular fa-clock"></i>
                    Ce code expire dans <strong>15 minutes</strong>
                </div>
            </div>

            <p>Pour réinitialiser votre mot de passe, suivez ces étapes :</p>

            <div class="el-steps">
                <div class="el-step-item">
                    <div class="el-step-number">1</div>
                    <div class="el-step-text">Rendez-vous sur la page de réinitialisation de mot de passe ELChat.</div>
                </div>
                <div class="el-step-item">
                    <div class="el-step-number">2</div>
                    <div class="el-step-text">Entrez le code à 6 caractères affiché ci-dessus.</div>
                </div>
                <div class="el-step-item">
                    <div class="el-step-number">3</div>
                    <div class="el-step-text">Choisissez un nouveau mot de passe sécurisé et confirmez-le.</div>
                </div>
            </div>

            <div class="el-security-note">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <strong>Important :</strong> Si vous n'êtes pas à l'origine de cette demande, ignorez cet email.
                Votre mot de passe actuel reste inchangé. Ne communiquez jamais ce code à qui que ce soit,
                y compris à l'équipe ELChat.
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
