<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Réinitialisation de votre mot de passe ELChat</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <style>
        body {
            margin: 0;
            padding: 0;
            background-color: #ff9100;
            font-family: Helvetica, Arial, sans-serif;
        }

        #el-page-container {
            width: 100%;
            padding: 30px 10px;
            display: flex;
            justify-content: center;
        }

        #el-card-container {
            width: 600px;
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden;
        }

        .el-card-header {
            background-color: #fff3e0;
            padding: 20px;
            text-align: center;
        }

        .el-title {
            margin: 0;
            font-size: 24px;
            line-height: 28px;
            color: #333333;
        }

        .el-card-body {
            padding: 30px;
            color: #333333;
            font-size: 15px;
            line-height: 22px;
        }

        .el-card-body p {
            margin: 0 0 15px 0;
        }

        .el-container-code {
            display: flex;
            justify-content: center;
            margin: 20px 0;
        }

        .el-code-item {
            border: 2px solid #ff9100;
            background-color: #ffe0b2;
            padding: 12px 16px;
            margin-right: 8px;
            font-size: 18px;
            font-weight: bold;
            color: #333333;
            border-radius: 4px;
        }

        .el-code-item:last-child {
            margin-right: 0;
        }

        .el-disable {
            color: #999999;
            font-size: 13px;
            line-height: 18px;
            margin: 10px 0 0 0;
        }

        .el-best-regards {
            margin: 25px 0 0 0;
            color: #666666;
        }

        .el-card-footer {
            text-align: center;
            padding: 20px;
            border-top: 1px solid #eeeeee;
        }

        .el-divider {
            height: 1px;
            background-color: #eeeeee;
            margin-bottom: 12px;
        }

        .el-container a {
            color: #ff9100;
            margin: 0 6px;
            text-decoration: none;
            font-size: 20px;
        }
    </style>
</head>

<body>
<div id="el-page-container">
    <div id="el-card-container">

        <!-- HEADER -->
        <div class="el-card-header">
            <h2 class="el-title">Réinitialisation de votre mot de passe</h2>
        </div>

        <!-- BODY -->
        <div class="el-card-body">
            <p><strong>Bonjour {{ \Illuminate\Support\Str::title($user->firstname) }}</strong>,</p>

            <p>
                Vous avez demandé la réinitialisation de votre mot de passe sur <strong>ELChat</strong>, votre
                assistant intelligent qui vous accompagne 24h/24 et 7j/7 pour trouver rapidement toutes les informations
                dont vous avez besoin sur le site.
            </p>

            <p>
                Pour sécuriser votre compte et définir un nouveau mot de passe, veuillez utiliser le code ci-dessous :
            </p>

            <div class="el-container-code">
                @foreach (str_split($code) as $char)
                    <span class="el-code-item">{{ $char }}</span>
                @endforeach
            </div>

            <p>
                Ce code est valable pendant <strong>{{ $minutes }} minutes</strong>.
            </p>

            <p class="el-disable">
                Pour des raisons de sécurité, ne partagez jamais ce code avec qui que ce soit.
            </p>

            <p class="el-disable">
                Si vous n’êtes pas à l’origine de cette demande, vous pouvez ignorer cet email en toute sécurité.
                Aucun changement ne sera effectué sur votre compte.
            </p>

            <h3 class="el-best-regards">Cordialement,</h3>
            <h3>L’équipe ELChat</h3>
        </div>

        <!-- FOOTER -->
        <div class="el-card-footer">
            <div class="el-divider"></div>
            <p>&copy; 2026 ELChat. Tous droits réservés.</p>
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