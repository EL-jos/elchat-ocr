@extends('pages.layouts.blank')

@php
    $services = [
        'knowledge-rag' => [
            'name' => 'Knowledge Intelligence & RAG',
            'eyebrow' => 'Knowledge Intelligence',
            'title' => 'Base de connaissances RAG pour entreprises',
            'meta_description' => 'Centralisez vos sites, documents, FAQ et produits dans une base de connaissances RAG reliée aux réponses de votre IA.',
            'hero' => 'Transformez les contenus de votre entreprise en un contexte fiable que vos équipes et vos assistants IA peuvent exploiter.',
            'heading' => 'Des réponses fondées sur vos propres sources',
            'intro' => 'ELChat indexe les contenus utiles, recherche les passages pertinents et les remet dans le contexte de chaque demande. Vos réponses peuvent ainsi rester cohérentes, vérifiables et alignées sur votre activité.',
            'features' => [
                ['title' => 'Indexer vos sources', 'text' => 'Ajoutez votre site, un sitemap, des documents, des FAQ ou un catalogue produit dans un espace de connaissance structuré.'],
                ['title' => 'Retrouver le bon contexte', 'text' => 'La recherche hybride sélectionne les informations pertinentes avant de générer une réponse ou une analyse.'],
                ['title' => 'Citer les contenus utiles', 'text' => 'Les sources disponibles restent visibles pour aider les équipes à vérifier la réponse et à renforcer la confiance.'],
                ['title' => 'Repérer les lacunes', 'text' => 'Les questions sans réponse fiable révèlent les contenus à compléter, corriger ou réindexer.'],
            ],
        ],
        'workflows-connecteurs' => [
            'name' => 'Workflows & connecteurs métier',
            'eyebrow' => 'Automatisation métier',
            'title' => 'Workflows et connecteurs métier pour automatiser vos opérations',
            'meta_description' => 'Connectez CRM, e-commerce, agenda et outils d’équipe à des workflows IA encadrés par vos permissions et validations.',
            'hero' => 'Reliez vos outils métier et faites circuler le bon contexte vers les actions qui accélèrent réellement votre activité.',
            'heading' => 'De la demande à l’action autorisée',
            'intro' => 'ELChat coordonne les informations, les connecteurs et les étapes d’un processus. Un workflow peut rechercher une donnée, préparer une action ou l’exécuter lorsque les règles de votre organisation l’autorisent.',
            'features' => [
                ['title' => 'Connecter vos outils', 'text' => 'Reliez CRM, e-commerce, agendas, stockage, marketing et outils d’équipe dans un même environnement.'],
                ['title' => 'Composer des étapes', 'text' => 'Organisez recherches, décisions, validations et actions dans des workflows lisibles et réutilisables.'],
                ['title' => 'Respecter les permissions', 'text' => 'Chaque connecteur et chaque action dépendent des droits configurés pour le compte et le site concernés.'],
                ['title' => 'Tracer les exécutions', 'text' => 'Les résultats, confirmations et erreurs rendent les automatisations compréhensibles et améliorables.'],
            ],
        ],
        'intelligence-business' => [
            'name' => 'Business & Executive Intelligence',
            'eyebrow' => 'Intelligence décisionnelle',
            'title' => 'Intelligence business et executive pour décider avec le contexte',
            'meta_description' => 'Analysez les événements clients et opérationnels pour produire des diagnostics, briefings et plans d’action avec ELChat.',
            'hero' => 'Passez de données dispersées à une lecture claire des situations, des priorités et de leur impact métier.',
            'heading' => 'Des événements transformés en décisions utiles',
            'intro' => 'ELChat rapproche les connaissances de l’entreprise, les événements et les outils connectés pour aider les équipes à comprendre une situation et à choisir la prochaine action avec davantage de contexte.',
            'features' => [
                ['title' => 'Rassembler les signaux', 'text' => 'Conversations, conversions, opérations et événements métier sont replacés dans une même lecture.'],
                ['title' => 'Diagnostiquer une situation', 'text' => 'Les analyses s’appuient sur les sources disponibles pour distinguer faits observés, contexte et recommandations.'],
                ['title' => 'Prioriser les actions', 'text' => 'Les équipes peuvent transformer une analyse en plan d’action ordonné et relié à un objectif concret.'],
                ['title' => 'Mesurer l’impact', 'text' => 'Les résultats observés servent à comprendre ce qui fonctionne et ce qui doit être amélioré.'],
            ],
        ],
        'engagement-proactif' => [
            'name' => 'Engagement Proactif',
            'eyebrow' => 'Engagement Proactif',
            'title' => 'Engagement proactif et relance conversationnelle contextualisée',
            'meta_description' => 'Reprenez une conversation au bon moment grâce à un engagement proactif fondé sur les signaux et le contexte disponibles.',
            'hero' => 'Aidez un visiteur lorsqu’un signal vérifiable indique qu’une reprise de conversation peut être utile.',
            'heading' => 'Reprendre la conversation sans automatiser à l’aveugle',
            'intro' => 'L’Engagement Proactif rapproche l’intention observée, l’historique de la conversation et les règles de votre compte. Les messages restent soumis aux horaires, quotas, permissions, règles d’arrêt et validations prévues.',
            'features' => [
                ['title' => 'Observer le bon signal', 'text' => 'Une demande inachevée, une intention forte ou un événement connecté peut ouvrir une opportunité vérifiable.'],
                ['title' => 'Décider avec le contexte', 'text' => 'La mémoire, le résumé, le profil et le RAG disponibles servent à préparer une reprise cohérente.'],
                ['title' => 'Choisir le bon canal', 'text' => 'La relance est proposée sur un canal autorisé, dans le respect du moment et de la fréquence configurés.'],
                ['title' => 'Mesurer le résultat', 'text' => 'Réponses, leads, rendez-vous et conversions sont reliés à la séquence lorsque les données le permettent.'],
            ],
        ],
        'visitor-intelligence' => [
            'name' => 'Visitor Intelligence',
            'eyebrow' => 'Visitor Intelligence',
            'title' => 'Visitor Intelligence : analyser les parcours visiteurs',
            'meta_description' => 'Comprenez les parcours visiteurs avec les pages consultées, événements, captures du viewport et replays terminés d’ELChat.',
            'hero' => 'Observez ce qui se passe réellement sur votre site avant, pendant et après l’interaction avec ELChat.',
            'heading' => 'Une lecture concrète du parcours observé',
            'intro' => 'Visitor Intelligence relie les événements visibles du site aux interactions avec le widget. Les parcours terminés deviennent consultables dans le dashboard avec un replay adapté au périphérique du visiteur.',
            'features' => [
                ['title' => 'Capturer le bon périmètre', 'text' => 'Le système observe la zone visible du site hôte, sans capturer une page entière ni le scroll interne du widget.'],
                ['title' => 'Respecter le périphérique', 'text' => 'Le replay restitue le contexte desktop, mobile ou tablette enregistré pendant la visite.'],
                ['title' => 'Relier les signaux', 'text' => 'Navigation, clics, scrolls, inactivité, widget, CTA et conversions sont replacés dans l’ordre du parcours.'],
                ['title' => 'Repérer les points de friction', 'text' => 'Les événements aident à comprendre où l’attention baisse, où une aide manque ou où une conversion bloque.'],
            ],
        ],
        'agents-ia' => [
            'name' => 'Agents IA spécialisés',
            'eyebrow' => 'Agents IA',
            'title' => 'Agents IA spécialisés pour vos objectifs métier',
            'meta_description' => 'Déployez des agents IA spécialisés pour vos objectifs commerciaux et opérationnels avec des compétences et permissions configurables.',
            'hero' => 'Donnez à vos agents un rôle précis, les bonnes compétences et le niveau d’autonomie adapté à votre organisation.',
            'heading' => 'Une autonomie utile, progressive et contrôlée',
            'intro' => 'Les agents ELChat s’appuient sur vos connaissances, vos workflows et vos connecteurs. Ils peuvent préparer une réponse, recommander une action ou exécuter une tâche lorsque les permissions et confirmations nécessaires sont réunies.',
            'features' => [
                ['title' => 'Définir un objectif', 'text' => 'Chaque agent est orienté vers un rôle ou un processus précis plutôt que vers une automatisation indéfinie.'],
                ['title' => 'Associer des compétences', 'text' => 'Les agents utilisent les capacités, sources et workflows nécessaires à leur mission.'],
                ['title' => 'Régler l’autonomie', 'text' => 'Choisissez ce que l’agent peut lire, proposer, demander en confirmation ou exécuter.'],
                ['title' => 'Garder la traçabilité', 'text' => 'Les permissions, validations et journaux d’audit maintiennent l’humain dans la boucle.'],
            ],
        ],
        'ai-sales-hunter' => [
            'name' => 'AI Sales Hunter',
            'eyebrow' => 'Prospection IA',
            'title' => 'AI Sales Hunter pour identifier et qualifier vos prospects',
            'meta_description' => 'Identifiez, qualifiez et préparez vos prises de contact avec AI Sales Hunter, dans le respect de vos règles commerciales.',
            'hero' => 'Structurez votre prospection autour de signaux, de règles et d’actions vérifiables plutôt que de campagnes indistinctes.',
            'heading' => 'Une prospection IA guidée par vos priorités',
            'intro' => 'AI Sales Hunter aide les équipes commerciales à organiser la recherche de prospects, la qualification et la préparation des prises de contact. Les campagnes restent encadrées par les limites et validations configurées.',
            'features' => [
                ['title' => 'Cibler les bons profils', 'text' => 'Définissez les critères, secteurs et signaux qui correspondent à votre stratégie commerciale.'],
                ['title' => 'Qualifier avec le contexte', 'text' => 'Croisez les informations disponibles pour préparer une qualification compréhensible et vérifiable.'],
                ['title' => 'Préparer les prises de contact', 'text' => 'Générez des propositions adaptées au prospect et au canal retenu avant toute action sensible.'],
                ['title' => 'Contrôler les campagnes', 'text' => 'Limites, permissions, validations et journaux structurent chaque étape de la prospection.'],
            ],
        ],
    ];

    $service = $services[$slug] ?? null;
    if (!$service) {
        abort(404);
    }

    $localizedService = trans('site.services_details.' . str_replace('-', '_', $slug));
    if (is_array($localizedService)) {
        $service = $localizedService;
    }

    $service['meta_description'] = $service['meta_description'] ?? $service['description'] ?? '';

    $serviceUrl = \App\Support\SiteLocale::urlForPage('service', app()->getLocale(), $slug);
    $localeData = \App\Support\SiteLocale::languages()[app()->getLocale()] ?? \App\Support\SiteLocale::languages()['fr'];
@endphp

@section('seo')
    <title>{{ $service['title'] }} | ELChat</title>
    <meta name="title" content="{{ $service['title'] }} | ELChat">
    <meta name="description" content="{{ $service['meta_description'] }}">
    <meta name="language" content="{{ app()->getLocale() }}">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="{{ $serviceUrl }}">

    <meta property="og:type" content="website">
    <meta property="og:locale" content="{{ str_replace('-', '_', $localeData['hreflang']) }}">
    <meta property="og:site_name" content="ELChat">
    <meta property="og:title" content="{{ $service['title'] }} | ELChat">
    <meta property="og:description" content="{{ $service['meta_description'] }}">
    <meta property="og:url" content="{{ $serviceUrl }}">
    <meta property="og:image" content="https://elchat.io/assets/images/sub-banner-img.png">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $service['title'] }} | ELChat">
    <meta name="twitter:description" content="{{ $service['meta_description'] }}">
    <meta name="twitter:image" content="https://elchat.io/assets/images/sub-banner-img.png">
@endsection

@section('structured-data')
    <script type="application/ld+json">
        {!! json_encode([
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'Service',
                    '@id' => $serviceUrl . '#service',
                    'name' => $service['name'],
                    'serviceType' => $service['name'],
                    'description' => $service['meta_description'],
                    'url' => $serviceUrl,
                    'provider' => ['@id' => 'https://elchat.io/#organization'],
                ],
                [
                    '@type' => 'WebPage',
                    '@id' => $serviceUrl . '#webpage',
                    'url' => $serviceUrl,
                    'name' => $service['title'] . ' | ELChat',
                    'isPartOf' => ['@id' => 'https://elchat.io/#website'],
                    'about' => ['@id' => $serviceUrl . '#service'],
                    'breadcrumb' => [
                        '@type' => 'BreadcrumbList',
                        'itemListElement' => [
                            [
                                '@type' => 'ListItem',
                                'position' => 1,
                                'name' => __('site.nav.home'),
                                'item' => \App\Support\SiteLocale::urlForPage('home'),
                            ],
                            [
                                '@type' => 'ListItem',
                                'position' => 2,
                                'name' => __('site.nav.services'),
                                'item' => \App\Support\SiteLocale::urlForPage('services'),
                            ],
                            [
                                '@type' => 'ListItem',
                                'position' => 3,
                                'name' => $service['name'],
                                'item' => $serviceUrl,
                            ],
                        ],
                    ],
                    'inLanguage' => $localeData['hreflang'],
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
    </script>
@endsection

@section('main-content')
    <section class="float-left w-100 sub-banner-con position-relative main-box">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-7 col-md-7">
                    <div class="sub-banner-content-con">
                        <span class="special-text color-blue d-block">{{ $service['eyebrow'] }}</span>
                        <h1>{{ $service['title'] }}</h1>
                        <p>{{ $service['hero'] }}</p>
                        <div class="breadcrumb-con d-inline-block">
                            <ol class="breadcrumb mb-0">
                                <li class="breadcrumb-item"><a href="{{ \App\Support\SiteLocale::urlForPage('home') }}">{{ __('site.nav.home') }}</a></li>
                                <li class="breadcrumb-item"><a href="{{ \App\Support\SiteLocale::urlForPage('services') }}">{{ __('site.nav.services') }}</a></li>
                                <li class="breadcrumb-item active" aria-current="page">{{ $service['name'] }}</li>
                            </ol>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5 col-md-5">
                    <div class="sub-banner-img-con">
                        <figure>
                            <img src="{{ asset('assets/images/sub-banner-img.png') }}" alt="Illustration de {{ $service['name'] }}">
                        </figure>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="float-left w-100 position-relative why-choose-us-con padding-top padding-bottom main-box">
        <div class="container">
            <div class="heading-title-con text-center">
                <span class="special-text color-blue d-block">{{ __('site.services.detail_why') }}</span>
                <h2>{{ $service['heading'] }}</h2>
                <p class="mx-auto" style="max-width: 760px;">{{ $service['intro'] }}</p>
            </div>
            <div class="choose-outer-con">
                @foreach ($service['features'] as $feature)
                    <div class="choose-box">
                        <h6>{{ $feature['title'] }}</h6>
                        <p class="mb-0">{{ $feature['text'] }}</p>
                    </div>
                @endforeach
            </div>
            <div class="float-left w-100 m-auto text-center">
                <a href="{{ \App\Support\SiteLocale::urlForPage('contact') }}" class="text-decoration-none primary_btn d-inline-block">{{ __('site.services.contact') }}</a>
                <a href="{{ \App\Support\SiteLocale::urlForPage('services') }}" class="text-decoration-none secondary_btn d-inline-block ml-2">{{ __('site.services.all') }}</a>
            </div>
        </div>
    </section>
@endsection
