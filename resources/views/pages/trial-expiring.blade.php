<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Votre essai ELChat expire bientôt</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background:#f5f5f5; }
        .wrapper { max-width:600px; margin:40px auto; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 2px 20px rgba(0,0,0,.08); }
        .header { background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 100%); padding:36px 40px 28px; text-align:center; }
        .logo { font-size:26px; font-weight:800; color:#fff; }
        .logo span { color:#3b82f6; }
        .urgency-badge { display:inline-flex; align-items:center; gap:8px; background:rgba(245,158,11,.15); border:1px solid rgba(245,158,11,.35); color:#fbbf24; padding:8px 18px; border-radius:100px; font-size:13px; font-weight:700; margin-top:18px; }
        .body { padding:40px; }
        h1 { font-size:22px; font-weight:700; color:#1a1a1a; margin-bottom:8px; }
        p { font-size:15px; color:#4b5563; line-height:1.7; margin-bottom:16px; }
        .countdown { text-align:center; background:#fffbeb; border:1px solid #fde68a; border-radius:12px; padding:24px; margin:28px 0; }
        .countdown-number { font-size:56px; font-weight:900; color:#d97706; line-height:1; }
        .countdown-label { font-size:14px; color:#92400e; font-weight:600; margin-top:4px; }
        .cta-btn { display:block; text-align:center; background:#3b82f6; color:#fff; text-decoration:none; padding:15px 32px; border-radius:9px; font-size:15px; font-weight:700; margin:28px 0; }
        .plans-mini { border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; margin-bottom:28px; }
        .plan-row { display:flex; justify-content:space-between; align-items:center; padding:12px 16px; border-bottom:1px solid #f3f4f6; font-size:14px; }
        .plan-row:last-child { border-bottom:none; }
        .plan-row-name { font-weight:600; color:#1f2937; }
        .plan-row-price { color:#6b7280; }
        .plan-row-price strong { color:#3b82f6; }
        .footer { background:#f9fafb; border-top:1px solid #e5e7eb; padding:24px 40px; text-align:center; }
        .footer p { font-size:12px; color:#9ca3af; line-height:1.6; }
    </style>
</head>
<body>
<div class="wrapper">

    <div class="header">
        <div class="logo">EL<span>Chat</span></div>
        <div class="urgency-badge">
            {{ $daysLeft === 1 ? '⏰ Dernier jour !' : "⏳ Plus que {$daysLeft} jours" }}
        </div>
    </div>

    <div class="body">
        @php $user = $account->owner; @endphp

        <h1>{{ $user?->firstname ?? '' }}, votre essai se termine {{ $daysLeft === 1 ? 'demain' : "dans {$daysLeft} jours" }}</h1>

        <p>
            Votre période d'essai gratuite sur ELChat se termine le
            <strong>{{ $account->subscription?->trial_ends_at?->translatedFormat('d F Y') ?? '' }}</strong>.
            Pour ne pas perdre l'accès à votre compte et vos données, choisissez votre plan maintenant.
        </p>

        {{-- Compte à rebours visuel --}}
        <div class="countdown">
            <div class="countdown-number">{{ $daysLeft }}</div>
            <div class="countdown-label">{{ $daysLeft === 1 ? 'jour restant' : 'jours restants' }}</div>
        </div>

        <p>Voici nos offres, à partir de <strong>29 €/mois</strong> en abonnement annuel :</p>

        {{-- Mini tableau des plans --}}
        <div class="plans-mini">
            <div class="plan-row">
                <span class="plan-row-name">Starter</span>
                <span class="plan-row-price"><strong>29 €</strong>/mois (annuel)</span>
            </div>
            <div class="plan-row">
                <span class="plan-row-name">Business</span>
                <span class="plan-row-price"><strong>79 €</strong>/mois (annuel)</span>
            </div>
            <div class="plan-row">
                <span class="plan-row-name">Pro</span>
                <span class="plan-row-price"><strong>199 €</strong>/mois (annuel)</span>
            </div>
        </div>

        <a href="{{ url('/tarifs') }}" class="cta-btn">
            Choisir mon plan maintenant →
        </a>

        <p style="font-size:13px; color:#9ca3af; text-align:center;">
            Vos données sont en sécurité. Aucun engagement — annulation en un clic.
        </p>
    </div>

    <div class="footer">
        <p>
            © {{ date('Y') }} ELChat · <a href="{{ url('/') }}" style="color:#6b7280;">elchat.io</a>
            · <a href="mailto:contact@elchat.io" style="color:#6b7280;">contact@elchat.io</a>
        </p>
    </div>

</div>
</body>
</html>
