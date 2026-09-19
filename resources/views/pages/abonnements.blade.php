@extends('pages.layouts.blank')

@section('seo')
    @include('pages.partials.seo', ['page' => 'pricing'])
    {{--
    <!-- Primary Meta Tags -->
    <title>Tarifs plateforme IA entreprise | ELChat</title>
    <meta name="title" content="Tarifs plateforme IA entreprise | ELChat">
    <meta name="description"
          content="Découvrez les tarifs ELChat : Core, Community, Business Automation et Agentics pour déployer une IA opérationnelle adaptée à votre entreprise.">
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
    --}}
@endsection

@section('main-content')
    @php
        $pricingFeatureSets = [
            'core' => [
                __('site.services_details.knowledge_rag.name'),
                __('site.services_details.knowledge_rag.features.0.title'),
                __('site.services_details.knowledge_rag.features.1.title'),
                __('site.services_details.knowledge_rag.features.2.title'),
                __('site.services_details.knowledge_rag.features.3.title'),
                __('site.home.pricing_core_text'),
                __('site.pricing.start_core'),
            ],
            'community' => [
                __('site.pricing.addon') . ' Core', __('site.services.community_title'),
                __('site.services_details.engagement_proactif.name'), __('site.services_details.visitor_intelligence.name'),
                __('site.pricing.activate_community'),
            ],
            'automation' => [
                __('site.pricing.addon') . ' Core', __('site.services_details.workflows_connecteurs.name'),
                __('site.services_details.workflows_connecteurs.features.0.title'), __('site.services_details.workflows_connecteurs.features.1.title'),
                __('site.pricing.automate'),
            ],
            'agentics' => [
                __('site.pricing.addon') . ' Core', __('site.services_details.agents_ia.name'),
                __('site.services_details.agents_ia.features.1.title'), __('site.services_details.ai_sales_hunter.name'),
                __('site.services_details.ai_sales_hunter.features.3.title'), __('site.pricing.evaluate_agents'),
            ],
        ];
    @endphp

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
                        <h1>{{ __('site.pricing.title') }}</h1>
                        <p>
                            {{ __('site.pricing.hero') }}
                        </p>
                        <div class="breadcrumb-con d-inline-block">
                            <ol class="breadcrumb mb-0">
                                <li class="breadcrumb-item"><a href="{{ \App\Support\SiteLocale::urlForPage('home') }}">{{ __('site.nav.home') }}</a></li>
                                <li class="breadcrumb-item active" aria-current="page">{{ __('site.nav.pricing') }}</li>
                            </ol>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5 col-md-5">
                    <div class="sub-banner-img-con">
                        <figure>
                            <img src="{{ asset('assets/images/sub-banner-img.png') }}" alt="Illustration des tarifs et modules IA ELChat">
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
                <span class="special-text color-blue d-block wow fadeInLeft" data-wow-duration="2s" data-wow-delay="0.4s">{{ __('site.home.pricing_label') }}</span>
                <h2 class="wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.5s">
                    {{ __('site.home.pricing_title') }}
                </h2>
                <p>{{ __('site.home.pricing_intro') }}</p>
            </div>

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
                             <p>{{ __('site.home.pricing_core_text') }}</p>
                             <div class="generic-price d-inline-block">
                                <span class="d-block starting-at">{{ __('site.home.pricing_from') }}</span>
                                {{-- Prix dynamique --}}
                                <div class="elc-price-val" id="elc-price-starter">
                                    <sup class="d-inline-block font-weight-normal" id="elc-sym-starter">€</sup><span
                                            class="d-inline-block price-text font-weight-600"
                                            id="elc-val-starter">29</span><span
                                            class="d-inline-block per-month mb-0 position-relative font-weight-normal">{{ __('site.home.per_month') }}</span>
                                </div>
                                 <span class="elc-price-note" id="elc-note-starter">{{ __('site.home.pricing_core_text') }}</span>
                                <span class="elc-price-suffix" id="elc-suffix-starter">{{ __('site.pricing.annual_subscription') }}</span>
                            </div>
                        </div>
                        <div class="plan-listing">
                            <ul class="list-unstyled p-0">
                                @foreach ($pricingFeatureSets['core'] as $feature)
                                    <li class="position-relative"><i class="fa-solid fa-check"></i> {{ $feature }}</li>
                                @endforeach
                            </ul>
                             <a href="{{ \App\Support\SiteLocale::urlForPage('contact') }}" class="text-decoration-none primary_btn">{{ __('site.pricing.start_core') }}</a>
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
                             <p>{{ __('site.pricing.community_text') }}</p>
                            <div class="generic-price d-inline-block">
                                <span class="d-block starting-at">{{ __('site.pricing.addon') }}</span>
                                <div class="elc-price-val" id="elc-price-business">
                                    <sup class="d-inline-block font-weight-normal" id="elc-sym-business">€</sup><span
                                            class="d-inline-block price-text font-weight-600"
                                             id="elc-val-business">19</span><span
                                            class="d-inline-block per-month mb-0 position-relative font-weight-normal">{{ __('site.home.per_month') }}</span>
                                </div>
                                 <span class="elc-price-note" id="elc-note-business">Basic +19 € · Pro +49 € / {{ __('site.home.per_month') }}</span>
                                <span class="elc-price-suffix" id="elc-suffix-business">{{ __('site.pricing.annual_subscription') }}</span>
                            </div>
                        </div>
                        <div class="plan-listing">
                            <ul class="list-unstyled p-0">
                                @foreach ($pricingFeatureSets['community'] as $feature)
                                    <li class="position-relative"><i class="fa-solid fa-check"></i> {{ $feature }}</li>
                                @endforeach
                            </ul>

                             <a href="{{ \App\Support\SiteLocale::urlForPage('contact') }}" class="text-decoration-none primary_btn">{{ __('site.pricing.activate_community') }}</a>
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
                             <p>{{ __('site.pricing.automation_text') }}</p>
                            <div class="generic-price d-inline-block">
                                <span class="d-block starting-at">{{ __('site.pricing.addon') }}</span>
                                <div class="elc-price-val" id="elc-price-pro">
                                    <sup class="d-inline-block font-weight-normal" id="elc-sym-pro">€</sup><span
                                            class="d-inline-block price-text font-weight-600"
                                             id="elc-val-pro">39</span><span
                                            class="d-inline-block per-month mb-0 position-relative font-weight-normal">{{ __('site.home.per_month') }}</span>
                                </div>
                                 <span class="elc-price-note" id="elc-note-pro">Basic +39 € · Pro +99 € / {{ __('site.home.per_month') }}</span>
                                <span class="elc-price-suffix" id="elc-suffix-pro">{{ __('site.pricing.annual_subscription') }}</span>
                            </div>
                        </div>
                        <div class="plan-listing">
                            <ul class="list-unstyled p-0">
                                @foreach ($pricingFeatureSets['automation'] as $feature)
                                    <li class="position-relative"><i class="fa-solid fa-check"></i> {{ $feature }}</li>
                                @endforeach
                            </ul>

                             <a href="{{ \App\Support\SiteLocale::urlForPage('contact') }}" class="text-decoration-none primary_btn">{{ __('site.pricing.automate') }}</a>
                        </div>
                    </div>
                </div>

                {{-- ────────────────────────────────────────────────────
                     🟠 ENTERPRISE
                     ──────────────────────────────────────────────────── --}}
                <div class="col-lg-4 col-md-6 all_column">
                    <div class="pricing-box w-100 all_boxes">
                        <div class="plan-content">
                             <h3>{{ __('site.pricing.agentics') }}</h3>
                            <p>
                                 {{ __('site.home.pricing_agents_text') }}
                            </p>
                            <div class="generic-price d-inline-block">
                                <span class="d-block starting-at">{{ __('site.pricing.addon') }}</span>
                                <sup class="d-inline-block font-weight-normal">€</sup>
                                 <span class="d-inline-block price-text font-weight-600">59</span>
                                <span class="d-inline-block per-month mb-0 position-relative font-weight-normal">{{ __('site.home.per_month') }}</span>
                            </div>
                        </div>
                        <div class="plan-listing">
                            <ul class="list-unstyled p-0">
                                @foreach ($pricingFeatureSets['agentics'] as $feature)
                                    <li class="position-relative"><i class="fa-solid fa-check"></i> {{ $feature }}</li>
                                @endforeach
                            </ul>

                             <a href="{{ \App\Support\SiteLocale::urlForPage('contact') }}" class="text-decoration-none primary_btn">{{ __('site.pricing.evaluate_agents') }}</a>
                        </div>
                    </div>
                </div>

            </div>{{-- /row --}}

            {{-- ════════════════════════════════════════════════════════
                 BADGES MOYENS DE PAIEMENT ACCEPTÉS
                 ════════════════════════════════════════════════════════ --}}

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
