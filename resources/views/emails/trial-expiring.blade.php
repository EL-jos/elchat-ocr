<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Votre essai ELChat expire bientôt</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <style>
        body { margin:0; padding:0; background-color:#ff9100; font-family:Helvetica,Arial,sans-serif; }
        #el-page-container { display:flex; justify-content:center; padding:30px 10px; }
        #el-card-container { background-color:#ffffff; border-radius:8px; width:600px; overflow:hidden; }
        .el-card-header { background-color:#fff3e0; padding:20px; text-align:center; }
        .el-card-header h2 { margin:0; color:#333333; font-size:24px; }
        .el-card-body { padding:30px; color:#333333; font-size:15px; line-height:22px; }
        .el-card-body p { margin-bottom:15px; }
        /* Countdown */
        .el-countdown {
            text-align:center; background-color:#fff3e0;
            border:2px dashed #ff9100; border-radius:10px;
            padding:24px; margin:24px 0;
        }
        .el-countdown-number { font-size:60px; font-weight:900; color:#e65c00; line-height:1; }
        .el-countdown-label { font-size:14px; color:#e65c00; font-weight:700; margin-top:6px; text-transform:uppercase; letter-spacing:1px; }
        /* Plans mini table */
        .el-plans-table { width:100%; border-collapse:collapse; margin:16px 0 24px; }
        .el-plans-table th {
            background-color:#fff3e0; padding:9px 14px;
            font-size:12px; text-transform:uppercase; letter-spacing:1px;
            color:#e65c00; text-align:left; font-weight:bold;
        }
        .el-plans-table td { padding:10px 14px; font-size:14px; border-bottom:1px solid #f3f4f6; color:#333333; }
        .el-plans-table tr:last-child td { border-bottom:none; }
        .el-plans-table .price { font-weight:700; color:#ff9100; }
        /* CTA */
        .el-cta { text-align:center; margin:28px 0; }
        .el-cta a {
            display:inline-block; background-color:#ff9100;
            color:#ffffff; text-decoration:none;
            padding:14px 36px; border-radius:8px;
            font-size:15px; font-weight:bold;
        }
        /* Info box */
        .el-info-box {
            background-color:#fff8f0; border-left:4px solid #ff9100;
            padding:12px 16px; border-radius:0 6px 6px 0;
            font-size:13px; color:#666666; margin:20px 0;
        }
        .el-info-box i { color:#ff9100; margin-right:6px; }
        .el-best-regards { margin-top:25px; }
        .el-card-footer { padding:20px; border-top:1px solid #eeeeee; text-align:center; font-size:13px; color:#999999; }
        .el-card-footer .el-container a { margin:0 6px; color:#999999; text-decoration:none; }
    </style>
</head>
<body>
<div id="el-page-container">
    <div id="el-card-container">

        <div class="el-card-header">
            <h2>
                @if($daysLeft === 1)
                    ⏰ Dernier jour d'essai !
                @else
                    ⏳ Plus que {{ $daysLeft }} jours d'essai
                @endif
            </h2>
        </div>

        <div class="el-card-body">
            @php $user = $account->owner; @endphp

            <p><strong>Bonjour {{ \Illuminate\Support\Str::title($user->firstname) }},</strong></p>

            <p>
                Votre période d'essai gratuite sur <strong>ELChat</strong> se termine
                le <strong>{{ $account->subscription?->trial_ends_at?->translatedFormat('d F Y') ?? '' }}</strong>.
                Pour continuer à bénéficier de toutes les fonctionnalités et ne pas perdre l'accès
                à votre compte et vos données, choisissez votre plan dès maintenant.
            </p>

            <div class="el-countdown">
                <div class="el-countdown-number">{{ $daysLeft }}</div>
                <div class="el-countdown-label">
                    {{ $daysLeft === 1 ? 'jour restant' : 'jours restants' }}
                </div>
            </div>

            <p><strong>Nos offres, à partir de 29 €/mois :</strong></p>

            <table class="el-plans-table">
                <tr>
                    <th>Plan</th>
                    <th>Annuel (économique)</th>
                    <th>Mensuel</th>
                </tr>
                <tr>
                    <td><strong>Starter</strong></td>
                    <td class="price">29 € / mois</td>
                    <td>34 € / mois</td>
                </tr>
                <tr>
                    <td><strong>Business</strong></td>
                    <td class="price">79 € / mois</td>
                    <td>89 € / mois</td>
                </tr>
                <tr>
                    <td><strong>Pro</strong></td>
                    <td class="price">199 € / mois</td>
                    <td>224 € / mois</td>
                </tr>
                <tr>
                    <td><strong>Enterprise</strong></td>
                    <td colspan="2" style="color:#999999;">Sur devis — <a href="mailto:contact@elchat.io" style="color:#ff9100;">nous contacter</a></td>
                </tr>
            </table>

            <div class="el-cta">
                <a href="{{ url('/tarifs') }}">Choisir mon plan maintenant →</a>
            </div>

            <div class="el-info-box">
                <i class="fa-solid fa-shield-halved"></i>
                <strong>Vos données sont en sécurité.</strong>
                Aucun engagement — annulation en un clic depuis votre espace client.
                Paiement sécurisé par Stripe ou PayPal.
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
