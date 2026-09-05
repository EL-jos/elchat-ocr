<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bienvenue sur ELChat</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <style>
        body {
            margin: 0;
            padding: 0;
            background-color: #ff9100;
            font-family: Helvetica, Arial, sans-serif;
        }
        #el-page-container {
            display: flex;
            justify-content: center;
            padding: 30px 10px;
        }
        #el-card-container {
            background-color: #ffffff;
            border-radius: 8px;
            width: 600px;
            overflow: hidden;
        }
        .el-card-header {
            background-color: #fff3e0;
            padding: 20px;
            text-align: center;
        }
        .el-card-header h2 {
            margin: 0;
            color: #333333;
            font-size: 24px;
        }
        .el-card-body {
            padding: 30px;
            color: #333333;
            font-size: 15px;
            line-height: 22px;
        }
        .el-container-info {
            margin: 20px 0;
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
        }
        .el-info-item {
            width: 48%;
            margin-bottom: 10px;
        }
        .el-info-item p {
            margin: 0 0 4px 0;
            font-weight: bold;
        }
        .el-info-item span {
            color: #555555;
        }
        .el-container-features {
            margin: 20px 0;
        }
        .el-feature-item {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
        }
        .el-icon {
            width: 24px;
            height: 24px;
            background-color: #ff9100;
            color: #ffffff;
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            margin-right: 10px;
            font-size: 14px;
        }
        .el-card-body p {
            margin-bottom: 15px;
        }
        .el-best-regards {
            margin-top: 25px;
        }
        .el-card-footer {
            padding: 20px;
            border-top: 1px solid #eeeeee;
            text-align: center;
            font-size: 13px;
            color: #999999;
        }
        .el-card-footer .el-container a {
            margin: 0 6px;
            color: #999999;
            text-decoration: none;
        }
    </style>
</head>

<body>
<div id="el-page-container">
    <div id="el-card-container">
        <div class="el-card-header">
            <h2 class="el-title">Bienvenue sur ELChat 🎉</h2>
        </div>
        <div class="el-card-body">
            <p><strong>Bonjour {{ \Illuminate\Support\Str::title($user->firstname) }},</strong></p>

            <p>
                Nous sommes ravis de vous accueillir sur <strong>ELChat</strong>, votre assistant intelligent qui vous guide et répond à toutes vos questions directement sur le site que vous visitez.
                Avec ELChat, vous trouvez rapidement l’information dont vous avez besoin et profitez d’une expérience fluide et personnalisée.
            </p>

            <div class="el-container-info">
                <div class="el-info-item">
                    <p>Nom</p>
                    <span>{{ \Illuminate\Support\Str::title($user->firstname) . ' ' . \Illuminate\Support\Str::upper($user->lastname) }}</span>
                </div>
                <div class="el-info-item">
                    <p>E-mail</p>
                    <span>{{ $user->email }}</span>
                </div>
                <div class="el-info-item">
                    <p>Date d'inscription</p>
                    <span>{{ $user->created_at->format('d/m/Y') }}</span>
                </div>
            </div>

            <p>
                Votre compte est désormais actif et vous pouvez commencer à utiliser ELChat pour naviguer et obtenir des réponses instantanées et précises à vos questions.
            </p>

            <div class="el-container-features">
                <div class="el-feature-item">
                    <div class="el-icon"><i class="fa-solid fa-check"></i></div>
                    <span>Réponses instantanées et contextualisées</span>
                </div>
                <div class="el-feature-item">
                    <div class="el-icon"><i class="fa-solid fa-check"></i></div>
                    <span>Accès rapide aux informations essentielles du site</span>
                </div>
                <div class="el-feature-item">
                    <div class="el-icon"><i class="fa-solid fa-check"></i></div>
                    <span>Navigation fluide et expérience utilisateur optimisée</span>
                </div>
                <div class="el-feature-item">
                    <div class="el-icon"><i class="fa-solid fa-check"></i></div>
                    <span>Disponible 24/7 pour répondre à vos besoins</span>
                </div>
            </div>

            <p>
                Avec ELChat, votre visite devient plus rapide, plus efficace et plus agréable. Trouvez ce que vous cherchez, explorez facilement les contenus et profitez d’une expérience intelligente sur le site.
            </p>

            <h3 class="el-best-regards">Cordialement,</h3>
            <h3>L’équipe ELChat</h3>
        </div>

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