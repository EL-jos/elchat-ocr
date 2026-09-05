@extends('pages.layouts.blank')

@section('seo')
    <!-- Primary Meta Tags -->
    <title>Tarifs ELChat | Core et modules d'IA opérationnelle</title>
    <meta name="title" content="Tarifs ELChat | Core et modules d'IA opérationnelle">
    <meta name="description"
          content="Core à 29 € par mois, puis Community, Business Automation et Agentics en Basic ou Pro. Découvrez la tarification modulaire d'ELChat.">
    <meta name="keywords"
          content="tarifs ELChat, prix plateforme IA entreprise, abonnement agents IA, tarifs automatisation métier, Core ELChat, Community ELChat, Agentics, offre Agency">
    <meta name="author" content="ELChat">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="https://elchat.io/tarifs">
    <!-- Open Graph -->
    <meta property="og:type" content="website">
    <meta property="og:locale" content="fr_FR">
    <meta property="og:site_name" content="ELChat">
    <meta property="og:title" content="Tarifs ELChat | Un socle Core, des capacités à la carte">
    <meta property="og:description" content="Commencez avec Core, puis ajoutez l'omnicanal, les workflows métier ou les agents IA selon les priorités de votre entreprise.">
    <meta property="og:url" content="https://elchat.io/tarifs">
    <meta property="og:image" content="https://elchat.io/assets/images/sub-banner-img.png">
    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Tarifs ELChat">
    <meta name="twitter:description" content="Une tarification modulaire pour activer les capacités ELChat utiles à votre organisation.">
    <meta name="twitter:image" content="https://elchat.io/assets/images/sub-banner-img.png">
@endsection

@section('main-content')

    {{-- ══════════════════════════════════════════════════════════════════
         STYLES INTERNES — uniquement pour les éléments de paiement ajoutés
         Ne touche pas aux classes CSS existantes du site
         ══════════════════════════════════════════════════════════════════ --}}
    <style>
        /* ── Toggle mensuel / annuel ──────────────────────────────── */
        .elc-billing-wrap {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-bottom: 36px;
            flex-wrap: wrap;
        }
        .elc-toggle {
            display: inline-flex;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 4px;
        }
        .elc-toggle-btn {
            padding: 8px 22px;
            border-radius: 7px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            background: transparent;
            color: #64748b;
            transition: all .2s;
        }
        .elc-toggle-btn.active {
            background: #fff;
            color: #1e293b;
            box-shadow: 0 1px 6px rgba(0,0,0,.12);
        }
        .elc-save-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #dcfce7;
            border: 1px solid #bbf7d0;
            color: #16a34a;
            font-size: 12px;
            font-weight: 700;
            padding: 5px 12px;
            border-radius: 100px;
        }

        /* ── Switcher devise ──────────────────────────────────────── */
        .elc-currency-wrap {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-bottom: 40px;
            flex-wrap: wrap;
        }
        .elc-currency-label {
            font-size: 13px;
            color: #64748b;
            font-weight: 500;
        }
        .elc-currency-select {
            appearance: none;
            background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E") no-repeat right 10px center;
            border: 1px solid #e2e8f0;
            color: #1e293b;
            padding: 7px 32px 7px 12px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            outline: none;
            transition: border-color .2s;
        }
        .elc-currency-select:focus { border-color: #3b82f6; }
        .elc-live-dot-wrap {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
            color: #94a3b8;
        }
        .elc-live-dot {
            width: 7px;
            height: 7px;
            background: #22c55e;
            border-radius: 50%;
            animation: elcPulse 2s infinite;
        }
        @keyframes elcPulse { 0%,100%{opacity:1} 50%{opacity:.35} }

        /* ── Prix dynamique ───────────────────────────────────────── */
        .elc-price-val {
            transition: opacity .15s ease;
        }
        .elc-price-val.loading { opacity: .3; }
        .elc-price-note {
            display: block;
            font-size: 11px;
            color: #16a34a;
            font-weight: 600;
            min-height: 16px;
            margin-top: 2px;
        }
        .elc-price-suffix {
            font-size: 12px;
            color: #94a3b8;
            font-weight: 400;
            margin-top: 2px;
            display: block;
        }

        /* ── Boutons de paiement ──────────────────────────────────── */
        .elc-cta-wrap {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-top: 18px;
        }
        /* Bouton Stripe — reprend le style primary_btn existant mais avec icône */
        .elc-btn-stripe {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            width: 100%;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 13.5px;
            font-weight: 700;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all .2s;
            background: #3b82f6;
            color: #fff;
        }
        .elc-btn-stripe:hover {
            opacity: .9;
            transform: translateY(-1px);
            color: #fff;
            text-decoration: none;
        }
        /* Séparateur */
        .elc-separator {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 11px;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: .5px;
        }
        .elc-separator::before,
        .elc-separator::after {
            content: '';
            flex: 1;
            border-top: 1px solid #e2e8f0;
        }
        /* Bouton PayPal */
        .elc-btn-paypal {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            width: 100%;
            padding: 11px 16px;
            border-radius: 8px;
            font-size: 13.5px;
            font-weight: 800;
            border: none;
            cursor: pointer;
            transition: all .2s;
            background: #ffc439;
            color: #003087;
            text-decoration: none;
        }
        .elc-btn-paypal:hover {
            background: #f0b429;
            transform: translateY(-1px);
            color: #003087;
            text-decoration: none;
        }
        /* Bouton Enterprise */
        .elc-btn-enterprise {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            width: 100%;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 13.5px;
            font-weight: 700;
            text-decoration: none;
            background: transparent;
            border: 1.5px solid currentColor;
            cursor: pointer;
            transition: all .2s;
            color: #3b82f6;
        }
        .elc-btn-enterprise:hover {
            background: rgba(59,130,246,.06);
            transform: translateY(-1px);
            text-decoration: none;
        }

        /* ── Badges paiement acceptés ─────────────────────────────── */
        .elc-payment-badges {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 32px;
            padding-top: 28px;
            border-top: 1px solid #e2e8f0;
        }
        .elc-payment-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 5px 11px;
            border-radius: 7px;
            font-size: 12px;
            color: #64748b;
            font-weight: 500;
        }

        /* ── Banners (reason / erreur) ────────────────────────────── */
        .elc-banner {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 14px 20px;
            border-radius: 10px;
            margin-bottom: 28px;
            font-size: 14px;
            font-weight: 500;
        }
        .elc-banner.warning { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
        .elc-banner.error   { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .elc-banner.info    { background: #eff6ff; border: 1px solid #bfdbfe; color: #1d4ed8; }
        .elc-banner.success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }

        /* ── Toast ────────────────────────────────────────────────── */
        #elc-toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: #1e293b;
            color: #f1f5f9;
            padding: 13px 20px;
            border-radius: 10px;
            font-size: 14px;
            box-shadow: 0 8px 32px rgba(0,0,0,.25);
            z-index: 9999;
            transform: translateY(80px);
            opacity: 0;
            transition: all .3s cubic-bezier(.175,.885,.32,1.275);
            max-width: 340px;
            border: 1px solid #334155;
        }
        #elc-toast.show { transform: translateY(0); opacity: 1; }
        #elc-toast.success { border-color: #22c55e44; }
        #elc-toast.error   { border-color: #ef444444; }
    </style>
    <style>
        .elc-cta-wrap { display:flex; flex-direction:column; gap:8px; margin-top:4px; }
        .elc-btn-stripe {
            display:flex; align-items:center; justify-content:center; gap:7px;
            width:100%; padding:12px 16px; border-radius:8px;
            font-size:13.5px; font-weight:700; text-decoration:none;
            border:none; cursor:pointer; transition:all .2s;
            background:var(--primary-color, #3b82f6); color:#fff;
        }
        .elc-btn-stripe:hover { opacity:.88; transform:translateY(-1px); color:#fff; text-decoration:none; }
        .elc-separator {
            display:flex; align-items:center; gap:8px;
            font-size:11px; color:#94a3b8; text-transform:uppercase; letter-spacing:.5px;
        }
        .elc-separator::before, .elc-separator::after { content:''; flex:1; border-top:1px solid #e2e8f0; }
        .elc-btn-paypal {
            display:flex; align-items:center; justify-content:center; gap:7px;
            width:100%; padding:11px 16px; border-radius:8px;
            font-size:13.5px; font-weight:800; border:none; cursor:pointer;
            transition:all .2s; background:#ffc439; color:#003087; text-decoration:none;
        }
        .elc-btn-paypal:hover { background:#f0b429; transform:translateY(-1px); color:#003087; text-decoration:none; }
        .elc-btn-enterprise {
            display:flex; align-items:center; justify-content:center; gap:7px;
            width:100%; padding:12px 16px; border-radius:8px;
            font-size:13.5px; font-weight:700; text-decoration:none;
            background:transparent; border:1.5px solid var(--primary-color,#3b82f6);
            cursor:pointer; transition:all .2s; color:var(--primary-color,#3b82f6);
        }
        .elc-btn-enterprise:hover { background:rgba(59,130,246,.06); transform:translateY(-1px); text-decoration:none; }
    </style>


    {{-- ══════════════════════════════════════════════════════════════════
         SUB BANNER SECTION — intact
         ══════════════════════════════════════════════════════════════════ --}}
    <section class="float-left w-100 sub-banner-con position-relative main-box">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-7 col-md-7">
                    <div class="sub-banner-content-con">
                        <h1>Tarifs</h1>
                        <p>
                            Commencez avec le socle Core, puis ajoutez les capacités omnicanales, les workflows métier ou les agents spécialisés au rythme de vos besoins.
                        </p>
                        <div class="breadcrumb-con d-inline-block">
                            <ol class="breadcrumb mb-0">
                                <li class="breadcrumb-item"><a href="{{ route('home.page') }}">Accueil</a></li>
                                <li class="breadcrumb-item active" aria-current="page">Tarifs</li>
                            </ol>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5 col-md-5">
                    <div class="sub-banner-img-con">
                        <figure>
                            <img src="{{ asset('assets/images/sub-banner-img.png') }}" alt="robot">
                        </figure>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ══════════════════════════════════════════════════════════════════
         PRICING PLAN SECTION
         ══════════════════════════════════════════════════════════════════ --}}
    <section class="float-left w-100 position-relative pricing-plan-con padding-top padding-bottom main-box main-pricing-con">
        <div class="container wow fadeInUp" data-wow-duration="2s" data-wow-delay="0.2s">

            {{-- ── Heading — intact ── --}}
            <div class="heading-title-con text-center">
                <span class="special-text color-blue d-block wow fadeInLeft" data-wow-duration="2s" data-wow-delay="0.4s">Tarification</span>
                <h2 class="wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.5s">
                    Un socle commun, des modules<br> adaptés à vos priorités
                </h2>
                <p>Core est la fondation de chaque abonnement. Community, Business Automation et Agentics s’ajoutent au niveau Basic ou Pro ; Agency répond aux besoins multi-clients et marque blanche sur devis.</p>
            </div>

            {{-- ════════════════════════════════════════════════════════
                 TOGGLE MENSUEL / ANNUEL
                 ════════════════════════════════════════════════════════ --}}
            <div class="elc-billing-wrap">
                <div class="elc-toggle">
                    <button class="elc-toggle-btn" id="elc-btn-monthly" onclick="elcSetBilling('monthly')">Mensuel</button>
                    <button class="elc-toggle-btn active" id="elc-btn-annual"  onclick="elcSetBilling('annual')">Annuel</button>
                </div>
                <span class="elc-save-badge" id="elc-save-badge">Facturation annuelle disponible</span>
            </div>

            {{-- ════════════════════════════════════════════════════════
                 SWITCHER DEVISE
                 ════════════════════════════════════════════════════════ --}}
            <div class="elc-currency-wrap">
                <span class="elc-currency-label">Afficher les prix en :</span>
                <select class="elc-currency-select" id="elc-currency" onchange="elcSetCurrency(this.value)">
                    <option value="EUR" {{ ($currency ?? 'EUR') === 'EUR' ? 'selected' : '' }}>🇪🇺 EUR (€)</option>
                    <option value="USD" {{ ($currency ?? 'EUR') === 'USD' ? 'selected' : '' }}>🇺🇸 USD ($)</option>
                    <option value="GBP">🇬🇧 GBP (£)</option>
                    <option value="CAD">🇨🇦 CAD (CA$)</option>
                    <option value="CHF">🇨🇭 CHF</option>
                    <option value="MAD">🇲🇦 MAD</option>
                </select>
                <div class="elc-live-dot-wrap">
                    <span class="elc-live-dot"></span>
                    Taux en temps réel
                </div>
            </div>

            {{-- ── Banners de redirection (trial expiré, etc.) ── --}}
            @if(request('reason'))
                @php
                    $elcReasons = [
                        'trial_expired'    => ['icon' => '⏰', 'cls' => 'warning', 'msg' => 'Votre période d\'essai de ' . ($trialDays ?? 7) . ' jours est terminée. Choisissez un plan pour continuer.'],
                        'past_due'         => ['icon' => '⚠️', 'cls' => 'error',   'msg' => 'Votre paiement est en retard. Mettez à jour votre moyen de paiement.'],
                        'canceled'         => ['icon' => '📋', 'cls' => 'warning', 'msg' => 'Votre abonnement a expiré. Choisissez un plan pour réactiver votre accès.'],
                        'no_subscription'  => ['icon' => '👋', 'cls' => 'info',    'msg' => 'Bienvenue ! Choisissez un plan pour accéder à ELChat.'],
                        'inactive'         => ['icon' => '🔒', 'cls' => 'error',   'msg' => 'Votre accès est suspendu. Veuillez souscrire à un abonnement.'],
                    ];
                    $elcReason = $elcReasons[request('reason')] ?? null;
                @endphp
                @if($elcReason)
                    <div class="elc-banner {{ $elcReason['cls'] }}">
                        {{ $elcReason['icon'] }}&nbsp;&nbsp;{{ $elcReason['msg'] }}
                    </div>
                @endif
            @endif
            @if(session('error'))
                <div class="elc-banner error">⚠️&nbsp;&nbsp;{{ session('error') }}</div>
            @endif
            @if(session('info'))
                <div class="elc-banner info">💬&nbsp;&nbsp;{{ session('info') }}</div>
            @endif
            @if(request('payment') === 'canceled')
                <div class="elc-banner warning">↩️&nbsp;&nbsp;Paiement annulé. Vous pouvez réessayer quand vous le souhaitez.</div>
            @endif

            {{-- ════════════════════════════════════════════════════════
                 PLANS GRID — structure HTML originale conservée à 100%
                 Seuls les prix et les boutons CTA sont remplacés
                 ════════════════════════════════════════════════════════ --}}
            <div class="row all_row wow fadeInDown" data-wow-duration="2s" data-wow-delay="0.6s">

                {{-- ────────────────────────────────────────────────────
                     🟢 STARTER
                     ──────────────────────────────────────────────────── --}}
                <div class="col-lg-4 col-md-6 all_column">
                    <div class="pricing-box w-100 all_boxes">
                        <div class="plan-content">
                             <h3>Core</h3>
                             <p>Le socle obligatoire pour connecter vos connaissances et déployer l’assistant ELChat.</p>
                             <div class="generic-price d-inline-block">
                                <span class="d-block starting-at">À partir de :</span>
                                {{-- Prix dynamique --}}
                                <div class="elc-price-val" id="elc-price-starter">
                                    <sup class="d-inline-block font-weight-normal" id="elc-sym-starter">€</sup><span
                                            class="d-inline-block price-text font-weight-600"
                                            id="elc-val-starter">29</span><span
                                            class="d-inline-block per-month mb-0 position-relative font-weight-normal">/mois</span>
                                </div>
                                 <span class="elc-price-note" id="elc-note-starter">Socle de la plateforme</span>
                                <span class="elc-price-suffix" id="elc-suffix-starter">Abonnement annuel</span>
                            </div>
                        </div>
                        <div class="plan-listing">
                            <ul class="list-unstyled p-0">
                                 <li class="position-relative"><i class="fa-solid fa-check"></i> Base de connaissances et RAG</li>
                                 <li class="position-relative"><i class="fa-solid fa-check"></i> Indexation de sites et sitemaps</li>
                                 <li class="position-relative"><i class="fa-solid fa-check"></i> Import de documents et contenus</li>
                                 <li class="position-relative"><i class="fa-solid fa-check"></i> Réponses contextualisées avec sources</li>
                                 <li class="position-relative"><i class="fa-solid fa-check"></i> Widget conversationnel personnalisable</li>
                                 <li class="position-relative"><i class="fa-solid fa-check"></i> Historique et analytique des interactions</li>
                                 <li class="position-relative"><i class="fa-solid fa-check"></i> Gestion de la qualité des connaissances</li>
                                 <li class="position-relative"><i class="fa-solid fa-check"></i> Modules optionnels activables</li>
                                 <li class="position-relative"><i class="fa-solid fa-check"></i> 29 € / mois</li>
                            </ul>
                             <a href="{{ route('contact.page') }}" class="text-decoration-none primary_btn">Démarrer avec Core</a>
                        </div>
                    </div>
                </div>

                {{-- ────────────────────────────────────────────────────
                     🟡 BUSINESS (highlighted — el-default-pricing)
                     ──────────────────────────────────────────────────── --}}
                <div class="col-lg-4 col-md-6 all_column">
                    <div class="el-default-pricing pricing-box w-100 all_boxes">
                        <div class="plan-content">
                             <h3>Community</h3>
                             <p>Pour centraliser et automatiser l’engagement sur vos canaux clients.</p>
                            <div class="generic-price d-inline-block">
                                <span class="d-block starting-at">En complément de Core :</span>
                                <div class="elc-price-val" id="elc-price-business">
                                    <sup class="d-inline-block font-weight-normal" id="elc-sym-business">€</sup><span
                                            class="d-inline-block price-text font-weight-600"
                                             id="elc-val-business">19</span><span
                                            class="d-inline-block per-month mb-0 position-relative font-weight-normal">/mois</span>
                                </div>
                                 <span class="elc-price-note" id="elc-note-business">Basic +19 € · Pro +49 €</span>
                                <span class="elc-price-suffix" id="elc-suffix-business">Abonnement annuel</span>
                            </div>
                        </div>
                        <div class="plan-listing">
                            <ul class="list-unstyled p-0">
                                 <li class="position-relative"><i class="fa-solid fa-check"></i> En complément de Core</li>
                                 <li class="position-relative"><i class="fa-solid fa-check"></i> Canaux sociaux connectés</li>
                                 <li class="position-relative"><i class="fa-solid fa-check"></i> Messageries et e-mail</li>
                                 <li class="position-relative"><i class="fa-solid fa-check"></i> Interactions centralisées</li>
                                 <li class="position-relative"><i class="fa-solid fa-check"></i> Basic à +19 € / mois</li>
                                 <li class="position-relative"><i class="fa-solid fa-check"></i> Pro à +49 € / mois</li>
                            </ul>

                             <a href="{{ route('contact.page') }}" class="text-decoration-none primary_btn">Activer l’omnicanal</a>
                        </div>
                    </div>
                </div>

                {{-- ────────────────────────────────────────────────────
                     🔵 PRO
                     ──────────────────────────────────────────────────── --}}
                <div class="col-lg-4 col-md-6 all_column">
                    <div class="pricing-box w-100 all_boxes">
                        <div class="plan-content">
                             <h3>Business Automation</h3>
                             <p>Pour connecter vos outils et orchestrer des workflows métier contrôlés.</p>
                            <div class="generic-price d-inline-block">
                                <span class="d-block starting-at">En complément de Core :</span>
                                <div class="elc-price-val" id="elc-price-pro">
                                    <sup class="d-inline-block font-weight-normal" id="elc-sym-pro">€</sup><span
                                            class="d-inline-block price-text font-weight-600"
                                             id="elc-val-pro">39</span><span
                                            class="d-inline-block per-month mb-0 position-relative font-weight-normal">/mois</span>
                                </div>
                                 <span class="elc-price-note" id="elc-note-pro">Basic +39 € · Pro +99 €</span>
                                <span class="elc-price-suffix" id="elc-suffix-pro">Abonnement annuel</span>
                            </div>
                        </div>
                        <div class="plan-listing">
                            <ul class="list-unstyled p-0">
                                 <li class="position-relative"><i class="fa-solid fa-check"></i> En complément de Core</li>
                                 <li class="position-relative"><i class="fa-solid fa-check"></i> Workflows et connecteurs métier</li>
                                 <li class="position-relative"><i class="fa-solid fa-check"></i> CRM, e-commerce et agendas</li>
                                 <li class="position-relative"><i class="fa-solid fa-check"></i> Productivité, stockage et marketing</li>
                                 <li class="position-relative"><i class="fa-solid fa-check"></i> Basic à +39 € / mois</li>
                                 <li class="position-relative"><i class="fa-solid fa-check"></i> Pro à +99 € / mois</li>
                            </ul>

                             <a href="{{ route('contact.page') }}" class="text-decoration-none primary_btn">Automatiser vos processus</a>
                        </div>
                    </div>
                </div>

                {{-- ────────────────────────────────────────────────────
                     🟠 ENTERPRISE
                     ──────────────────────────────────────────────────── --}}
                <div class="col-lg-4 col-md-6 all_column">
                    <div class="pricing-box w-100 all_boxes">
                        <div class="plan-content">
                             <h3>Agentics</h3>
                            <p>
                                 Pour déployer des agents IA spécialisés, configurés autour de vos objectifs et de vos règles d’autonomie.
                            </p>
                            <div class="generic-price d-inline-block">
                                <span class="d-block starting-at">En complément de Core :</span>
                                <sup class="d-inline-block font-weight-normal">€</sup>
                                 <span class="d-inline-block price-text font-weight-600">59</span>
                                <span class="d-inline-block per-month mb-0 position-relative font-weight-normal">/mois</span>
                            </div>
                        </div>
                        <div class="plan-listing">
                            <ul class="list-unstyled p-0">
                                 <li class="position-relative"><i class="fa-solid fa-check"></i> En complément de Core</li>
                                 <li class="position-relative"><i class="fa-solid fa-check"></i> Banque d’agents spécialisés</li>
                                 <li class="position-relative"><i class="fa-solid fa-check"></i> Agent Studio et workflows associés</li>
                                 <li class="position-relative"><i class="fa-solid fa-check"></i> AI Sales Hunter</li>
                                 <li class="position-relative"><i class="fa-solid fa-check"></i> Basic à +59 € / mois</li>
                                 <li class="position-relative"><i class="fa-solid fa-check"></i> Pro à +149 € / mois</li>
                                 <li class="position-relative"><i class="fa-solid fa-check"></i> Agency multi-clients sur devis</li>
                                 <li class="position-relative"><i class="fa-solid fa-check"></i> Marque blanche disponible avec Agency</li>
                            </ul>

                             <a href="{{ route('contact.page') }}" class="text-decoration-none primary_btn">Évaluer Agentics ou Agency</a>
                        </div>
                    </div>
                </div>

            </div>{{-- /row --}}

            {{-- ════════════════════════════════════════════════════════
                 BADGES MOYENS DE PAIEMENT ACCEPTÉS
                 ════════════════════════════════════════════════════════ --}}
            <div class="elc-payment-badges">
                <span style="font-size:12px; color:#64748b; font-weight:600;">Paiements acceptés :</span>
                <span class="elc-payment-badge">💳 Visa / Mastercard</span>
                <span class="elc-payment-badge">💳 American Express</span>
                <span class="elc-payment-badge">🏦 Virement SEPA</span>
                <span class="elc-payment-badge" style="color:#003087; font-weight:700; background:#fff9e6; border-color:#fde68a;">
                <svg width="44" height="11" viewBox="0 0 124 33" xmlns="http://www.w3.org/2000/svg">
                    <path d="M46.2 6.8h-8.5c-.6 0-1.1.4-1.2 1l-3.4 21.7c-.1.4.2.8.6.8h4.1c.6 0 1.1-.4 1.2-1l.9-5.9c.1-.6.6-1 1.2-1h2.7c5.6 0 8.8-2.7 9.7-8.1.4-2.3 0-4.2-1.1-5.5-1.2-1.4-3.4-2-6.2-2zm1 8c-.5 3-2.7 3-4.9 3h-1.2l.9-5.6c0-.3.3-.6.7-.6h.6c1.5 0 2.9 0 3.6.8.4.5.5 1.3.3 2.4z" fill="#003087"/><path d="M75.8 14.7h-4.1c-.3 0-.6.2-.7.6l-.2 1-.3-.4c-.9-1.3-2.9-1.8-4.9-1.8-4.6 0-8.5 3.5-9.3 8.4-.4 2.4.2 4.8 1.6 6.4 1.3 1.5 3.1 2.1 5.3 2.1 3.7 0 5.8-2.4 5.8-2.4l-.2 1c-.1.4.2.8.6.8h3.7c.6 0 1.1-.4 1.2-1l2.2-14.1c.1-.5-.2-.6-.7-.6zm-5.7 8.2c-.4 2.3-2.3 3.9-4.7 3.9-1.2 0-2.2-.4-2.8-1.1-.6-.7-.8-1.8-.6-3 .4-2.3 2.3-3.9 4.6-3.9 1.2 0 2.1.4 2.8 1.1.7.8.9 1.8.7 3z" fill="#003087"/><path d="M99.8 14.7h-4.1c-.4 0-.7.2-.9.5l-5.4 7.9-2.3-7.6c-.1-.5-.6-.8-1-.8h-4c-.5 0-.8.5-.6.9l4.3 12.7-4.1 5.7c-.3.4 0 1 .5 1h4.1c.4 0 .7-.2.9-.5l13.1-18.9c.3-.3 0-.9-.5-.9z" fill="#003087"/><path d="M112.2 6.8h-8.5c-.6 0-1.1.4-1.2 1l-3.4 21.7c-.1.4.2.8.6.8h4.4c.4 0 .8-.3.8-.7l1-6.1c.1-.6.6-1 1.2-1h2.7c5.6 0 8.8-2.7 9.7-8.1.4-2.3 0-4.2-1.1-5.5-1.2-1.4-3.3-2-6.2-2zm1 8c-.5 3-2.7 3-4.9 3h-1.2l.9-5.6c0-.3.3-.6.7-.6h.6c1.5 0 2.9 0 3.6.8.4.5.5 1.3.3 2.4z" fill="#009cde"/><path d="M142 14.7h-4.1c-.3 0-.6.2-.7.6l-.2 1-.3-.4c-.9-1.3-2.9-1.8-4.9-1.8-4.6 0-8.5 3.5-9.3 8.4-.4 2.4.2 4.8 1.6 6.4 1.3 1.5 3.1 2.1 5.3 2.1 3.7 0 5.8-2.4 5.8-2.4l-.2 1c-.1.4.2.8.6.8h3.7c.6 0 1.1-.4 1.2-1l2.2-14.1c.1-.5-.2-.6-.7-.6zm-5.7 8.2c-.4 2.3-2.3 3.9-4.7 3.9-1.2 0-2.2-.4-2.8-1.1-.6-.7-.8-1.8-.6-3 .4-2.3 2.3-3.9 4.6-3.9 1.2 0 2.1.4 2.8 1.1.7.8.9 1.8.7 3z" fill="#009cde"/>
                </svg>
                PayPal
            </span>
                <span class="elc-payment-badge">🔒 SSL / 3D Secure</span>
            </div>

            {{-- Note trial --}}
            <p class="text-center mt-3" style="font-size:13px; color:#94a3b8;">
                 <strong style="color:#475569;">{{ $trialDays ?? 7 }} jours d'essai gratuit</strong> sur Core et les modules inclus dans l’essai — sans carte bancaire requise. Aucun engagement.
            </p>

        </div>{{-- /container --}}
    </section>

    {{-- Toast --}}
    <div id="elc-toast"></div>

    {{-- ══════════════════════════════════════════════════════════════════
         JAVASCRIPT — Toggle + Devise + Prix dynamique
         ══════════════════════════════════════════════════════════════════ --}}
    <script>
        // ─── Données des plans (injectées depuis Laravel) ─────────────────────────────
        const ELC = {
            billing:  'annual',
            currency: '{{ $currency ?? "EUR" }}',
            // Prix en centimes EUR — ordre : [mensuel, annuel]
            plans: {
                starter:  { monthly: 2900, annual: 2900, savings: 0 },
                business: { monthly: 1900, annual: 1900, savings: 0 },
                pro:      { monthly: 3900, annual: 3900, savings: 0 },
            },
            // Taux de change courant (mis à jour via API)
            rate: 1.0,
            // Symboles et codes
            symbols: { EUR:'€', USD:'$', GBP:'£', CAD:'CA$', CHF:'CHF ', MAD:'MAD ' },
            // Prix convertis (mis à jour par setCurrency)
            converted: {},
        };

        // ─── Initialiser les prix convertis depuis les données PHP ───────────────────
        @if(isset($plans))
                @foreach($plans as $plan)
                @if(!$plan['is_enterprise'])
            ELC.converted['{{ $plan['slug'] }}'] = {
            monthly:  { raw: {{ $plan['price_monthly_cents'] }}, fmt: '{{ $plan['monthly_price_formatted'] }}' },
            annual:   { raw: {{ $plan['price_annual_cents'] }},  fmt: '{{ $plan['annual_price_formatted'] }}' },
            savings:  '{{ $plan['annual_savings_formatted'] }}',
        };
        @endif
        @endforeach
        @endif

        // ─── Toggle mensuel / annuel ─────────────────────────────────────────────────
        function elcSetBilling(cycle) {
            ELC.billing = cycle;
            document.getElementById('elc-btn-monthly').classList.toggle('active', cycle === 'monthly');
            document.getElementById('elc-btn-annual').classList.toggle('active',  cycle === 'annual');

            const badge = document.getElementById('elc-save-badge');
            if (badge) badge.style.display = cycle === 'annual' ? 'inline-flex' : 'none';

            // Synchroniser tous les hidden inputs (Stripe ET PayPal)
            document.querySelectorAll('.elc-cycle-input').forEach(el => el.value = cycle);

            elcUpdatePrices();
        }

        // ─── Switcher devise ─────────────────────────────────────────────────────────
        async function elcSetCurrency(currency) {
            ELC.currency = currency;
            elcSetLoading(true);

            try {
                const res  = await fetch(`/api/currency/rates?currency=${currency}`);
                const data = await res.json();

                if (data.plans) {
                    data.plans.forEach(p => {
                        if (!ELC.converted[p.slug]) ELC.converted[p.slug] = {};
                        ELC.converted[p.slug].monthly = { fmt: p.monthly_formatted };
                        ELC.converted[p.slug].annual  = { fmt: p.annual_formatted  };
                        ELC.converted[p.slug].savings = p.annual_savings;
                    });
                    elcUpdatePrices();
                    elcToast(`Devise changée en ${currency}`, 'success');
                }
            } catch (e) {
                elcToast('Impossible de récupérer les taux. Veuillez réessayer.', 'error');
            } finally {
                elcSetLoading(false);
            }
        }

        // ─── Mise à jour affichage des prix ──────────────────────────────────────────
        function elcUpdatePrices() {
            const plans   = ['starter', 'business', 'pro'];
            const isAnnual = ELC.billing === 'annual';
            const sym      = ELC.symbols[ELC.currency] || ELC.currency + ' ';

            plans.forEach(slug => {
                const valEl    = document.getElementById(`elc-val-${slug}`);
                const symEl    = document.getElementById(`elc-sym-${slug}`);
                const noteEl   = document.getElementById(`elc-note-${slug}`);
                const suffixEl = document.getElementById(`elc-suffix-${slug}`);

                if (!valEl) return;

                const data = ELC.converted[slug];

                if (data) {
                    // Prix converti via API
                    const priceData = isAnnual ? data.annual : data.monthly;
                    const formatted = priceData?.fmt ?? '—';

                    // Séparer symbole et valeur pour conserver la mise en page existante
                    const numMatch = formatted.match(/[\d\s,.]+/);
                    const numPart  = numMatch ? numMatch[0].trim() : formatted;
                    const symPart  = formatted.replace(numPart, '').trim();

                    valEl.textContent    = numPart;
                    if (symEl) symEl.textContent = symPart || sym;
                    if (noteEl)   noteEl.textContent   = isAnnual && data.savings ? `Économisez ${data.savings} / an` : '';
                    if (suffixEl) suffixEl.textContent  = isAnnual ? 'Abonnement annuel' : 'Abonnement mensuel';
                } else {
                    // Fallback : prix EUR hardcodés
                    const raw   = ELC.plans[slug];
                    const price = isAnnual ? raw.annual / 100 : raw.monthly / 100;
                    valEl.textContent    = Math.round(price);
                    if (symEl)    symEl.textContent    = '€';
                    if (noteEl)   noteEl.textContent   = isAnnual && raw.savings ? `Économisez ${raw.savings / 100} € / an` : '';
                    if (suffixEl) suffixEl.textContent  = isAnnual ? 'Abonnement annuel' : 'Abonnement mensuel';
                }
            });
        }

        // ─── Loading ──────────────────────────────────────────────────────────────────
        function elcSetLoading(on) {
            document.querySelectorAll('.elc-price-val').forEach(el => el.classList.toggle('loading', on));
        }

        // ─── Toast ────────────────────────────────────────────────────────────────────
        let elcToastTimer;
        function elcToast(msg, type = 'info') {
            const t = document.getElementById('elc-toast');
            t.textContent = msg;
            t.className   = `show ${type}`;
            clearTimeout(elcToastTimer);
            elcToastTimer = setTimeout(() => t.className = '', 3500);
        }

        // ─── Init ─────────────────────────────────────────────────────────────────────
        document.addEventListener('DOMContentLoaded', () => {
            elcSetBilling('annual');   // Annuel par défaut
            elcUpdatePrices();
        });
    </script>

@endsection
