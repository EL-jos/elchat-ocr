<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Abonnement activé — ELChat</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <style>
        body { margin:0; padding:0; background-color:#ff9100; font-family:Helvetica,Arial,sans-serif; }
        #el-page-container { display:flex; justify-content:center; padding:30px 10px; }
        #el-card-container { background-color:#ffffff; border-radius:8px; width:600px; overflow:hidden; }
        .el-card-header { background-color:#fff3e0; padding:20px; text-align:center; }
        .el-card-header h2 { margin:0; color:#333333; font-size:24px; }
        .el-card-body { padding:30px; color:#333333; font-size:15px; line-height:22px; }
        .el-card-body p { margin-bottom:15px; }
        /* Badge succès */
        .el-success-badge {
            text-align:center; margin:20px 0 28px;
        }
        .el-success-badge .el-badge-inner {
            display:inline-flex; align-items:center; gap:8px;
            background-color:#f0fdf4; border:1px solid #bbf7d0;
            color:#166534; padding:8px 20px;
            border-radius:100px; font-size:13px; font-weight:700;
        }
        .el-success-badge i { color:#22c55e; }
        /* Tableau récap */
        .el-recap-table { width:100%; border-collapse:collapse; margin:20px 0; }
        .el-recap-table td { padding:10px 14px; font-size:14px; border-bottom:1px solid #f3f4f6; }
        .el-recap-table td:first-child { color:#999999; font-weight:600; width:45%; }
        .el-recap-table td:last-child { color:#333333; font-weight:700; }
        .el-recap-table tr:last-child td { border-bottom:none; }
        .el-recap-wrapper {
            background-color:#fafafa; border:1px solid #eeeeee;
            border-radius:8px; overflow:hidden; margin:20px 0;
        }
        .el-recap-title {
            background-color:#fff3e0; padding:10px 14px;
            font-size:12px; font-weight:bold; color:#e65c00;
            text-transform:uppercase; letter-spacing:1px;
        }
        /* Features */
        .el-container-features { margin:20px 0; }
        .el-feature-item { display:flex; align-items:center; margin-bottom:10px; }
        .el-icon {
            width:24px; height:24px; background-color:#ff9100;
            color:#ffffff; border-radius:50%;
            display:flex; justify-content:center; align-items:center;
            margin-right:10px; font-size:14px; flex-shrink:0;
        }
        .el-icon.star { background-color:#f59e0b; }
        /* CTA Button */
        .el-cta { text-align:center; margin:28px 0; }
        .el-cta a {
            display:inline-block; background-color:#ff9100;
            color:#ffffff; text-decoration:none;
            padding:14px 36px; border-radius:8px;
            font-size:15px; font-weight:bold; letter-spacing:0.3px;
        }
        /* Info box */
        .el-info-box {
            background-color:#fff8f0; border-left:4px solid #ff9100;
            padding:12px 16px; border-radius:0 6px 6px 0;
            font-size:13px; color:#666666; margin:20px 0;
        }
        .el-info-box i { color:#ff9100; margin-right:6px; }
        .el-info-box a { color:#e65c00; font-weight:bold; }
        .el-best-regards { margin-top:25px; }
        .el-card-footer { padding:20px; border-top:1px solid #eeeeee; text-align:center; font-size:13px; color:#999999; }
        .el-card-footer .el-container a { margin:0 6px; color:#999999; text-decoration:none; }
    </style>
</head>
<body>
<div id="el-page-container">
    <div id="el-card-container">

        <div class="el-card-header">
            <h2>Votre abonnement ELChat est activé ✅</h2>
        </div>

        <div class="el-card-body">
            @php
                $subscription = $account->subscription;
                $plan         = $subscription?->plan;
                $user         = $account->owner;
                $isAnnual     = $subscription?->billing_cycle === 'annual';
                $periodEnd    = $subscription?->current_period_end;
                $amount       = $subscription?->formatted_amount ?? '—';
                $provider     = $subscription?->payment_provider === 'paypal' ? 'PayPal' : 'Carte bancaire';
            @endphp

            <p><strong>Bonjour {{ \Illuminate\Support\Str::title($user->firstname) }},</strong></p>

            <p>
                Félicitations ! Votre abonnement <strong>ELChat {{ $plan?->name ?? 'Starter' }}</strong>
                est maintenant actif. Voici le récapitulatif complet de votre souscription.
            </p>

            <div class="el-success-badge">
                <div class="el-badge-inner">
                    <i class="fa-solid fa-circle-check"></i>
                    Paiement confirmé &amp; abonnement activé
                </div>
            </div>

            {{-- Récap abonnement --}}
            <div class="el-recap-wrapper">
                <div class="el-recap-title"><i class="fa-solid fa-file-invoice-dollar"></i> &nbsp;Récapitulatif</div>
                <table class="el-recap-table">
                    <tr>
                        <td>Plan</td>
                        <td>{{ $plan?->name ?? 'Starter' }}</td>
                    </tr>
                    <tr>
                        <td>Facturation</td>
                        <td>{{ $isAnnual ? 'Annuelle' : 'Mensuelle' }}</td>
                    </tr>
                    <tr>
                        <td>Montant</td>
                        <td>{{ $amount }} / mois</td>
                    </tr>
                    <tr>
                        <td>Moyen de paiement</td>
                        <td>{{ $provider }}</td>
                    </tr>
                    @if($periodEnd)
                        <tr>
                            <td>Prochain renouvellement</td>
                            <td>{{ $periodEnd->translatedFormat('d F Y') }}</td>
                        </tr>
                    @endif
                    <tr>
                        <td>Compte</td>
                        <td>{{ $account->name }}</td>
                    </tr>
                    <tr>
                        <td>Email</td>
                        <td>{{ $user->email }}</td>
                    </tr>
                </table>
            </div>

            {{-- Features incluses --}}
            @if($plan)
                <p><strong>Ce qui est inclus dans votre plan :</strong></p>
                <div class="el-container-features">
                    <div class="el-feature-item">
                        <div class="el-icon"><i class="fa-solid fa-check"></i></div>
                        <span>{{ $plan->max_sites }} site{{ $plan->max_sites > 1 ? 's' : '' }} web</span>
                    </div>
                    <div class="el-feature-item">
                        <div class="el-icon"><i class="fa-solid fa-check"></i></div>
                        <span>{{ $plan->max_social_networks_per_site }} réseau{{ $plan->max_social_networks_per_site > 1 ? 'x' : '' }} social / site</span>
                    </div>
                    <div class="el-feature-item">
                        <div class="el-icon"><i class="fa-solid fa-check"></i></div>
                        <span>Jusqu'à {{ number_format($plan->max_messages_per_month) }} messages / mois</span>
                    </div>
                    <div class="el-feature-item">
                        <div class="el-icon"><i class="fa-solid fa-check"></i></div>
                        <span>{{ $plan->formatted_chunks }} chunks de connaissances</span>
                    </div>
                    <div class="el-feature-item">
                        <div class="el-icon"><i class="fa-solid fa-check"></i></div>
                        <span>{{ $plan->formatted_tokens }} tokens IA</span>
                    </div>
                    @if($plan->has_sla)
                        <div class="el-feature-item">
                            <div class="el-icon star"><i class="fa-solid fa-star"></i></div>
                            <span>SLA Premium</span>
                        </div>
                    @endif
                    @if($plan->has_white_label)
                        <div class="el-feature-item">
                            <div class="el-icon star"><i class="fa-solid fa-star"></i></div>
                            <span>Option White-label</span>
                        </div>
                    @endif
                </div>
            @endif

            {{-- CTA --}}
            <div class="el-cta">
                <a href="{{ url('/app') }}">Accéder à mon tableau de bord →</a>
            </div>

            {{-- Info facturation --}}
            <div class="el-info-box">
                <i class="fa-solid fa-circle-info"></i>
                <strong>Gestion de votre abonnement :</strong> Modifiez votre plan, changez de moyen de paiement
                ou annulez à tout moment depuis votre
                @if($subscription?->payment_provider === 'paypal')
                    compte PayPal directement.
                @else
                    <a href="{{ url('/billing/portal') }}">portail de facturation</a>.
                    Vos factures PDF y sont également disponibles.
                @endif
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
