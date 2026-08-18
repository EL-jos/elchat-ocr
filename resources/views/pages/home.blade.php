@extends('pages.layouts.blank')

@section('seo')
    <!-- Primary Meta Tags -->
    <title>ELChat | Plateforme d'IA opérationnelle pour entreprises</title>

    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <meta name="title" content="ELChat | Plateforme d'IA opérationnelle pour entreprises">

    <meta name="description"
          content="ELChat relie connaissances, événements et outils métier pour aider les entreprises à décider, automatiser leurs processus, reprendre les conversations au bon moment et mesurer les résultats avec l'IA.">

    <meta name="keywords"
          content="plateforme IA entreprise, IA opérationnelle, engagement proactif, relance contextuelle, automatisation métier, agents IA, workflows IA, RAG entreprise, intelligence décisionnelle, connecteurs MCP, prospection IA, ELChat">

    <meta name="author" content="ELChat">
    <meta name="robots" content="index, follow">
    <meta name="language" content="fr">
    <meta name="revisit-after" content="7 days">

    <link rel="canonical" href="https://elchat.io/accueil">

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:locale" content="fr_FR">
    <meta property="og:site_name" content="ELChat">

    <meta property="og:title"
          content="ELChat | De la connaissance à l'action, dans une seule plateforme">

    <meta property="og:description"
          content="Unifiez vos connaissances, connectez vos outils et déployez des workflows et agents IA capables d'analyser, d'agir et de mesurer les résultats.">

    <meta property="og:url" content="https://elchat.io/accueil">

    <meta property="og:image"
          content="https://elchat.io/assets/images/sub-banner-img.png">

    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">

    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">

    <meta name="twitter:title"
          content="ELChat | Plateforme d'IA opérationnelle pour entreprises">

    <meta name="twitter:description"
          content="Une plateforme pour connaître, comprendre, décider, agir, mesurer et apprendre avec l'IA.">

    <meta name="twitter:image"
          content="https://elchat.io/assets/images/sub-banner-img.png">

    <!-- Theme -->
    <meta name="theme-color" content="#0F172A">
@endsection

@section('main-content')
    <!-- BANNER SECTION -->
    <section class="float-left w-100 banner-con position-relative main-box">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-7 col-md-7">
                    <div class="banner-content-con">
                        <ul class="list-unstyled p-0">
                            {{--<li class="position-relative d-inline-block"><i class="fa-solid fa-circle-check"></i>Essai gratuit de 14 jours</li>
                            <li class="position-relative d-inline-block"><i class="fa-solid fa-circle-check"></i>Aucune carte bancaire requise</li>--}}
                        </ul>
                        <h1>
                            L'<span class="d-inline-block font-weight-bold color-blue">IA</span> qui transforme vos <span class="d-inline-block font-weight-bold color-blue">connaissances</span> en actions
                        </h1>
                        <p>
                            Unifiez vos données, connectez vos outils et confiez à ELChat les tâches que l’IA peut réellement accélérer : comprendre le contexte, recommander une décision, reprendre une conversation au moment opportun, exécuter un workflow et mesurer son résultat.
                        </p>
                        <a href="{{ route('about.page') }}" class="text-decoration-none primary_btn d-inline-block">Découvrir la plateforme</a>
                        <a href="{{ route('contact.page') }}" class="text-decoration-none secondary_btn d-inline-block">Demander une démo</a>
                        <!-- banner content con -->
                    </div>
                    <!-- col -->
                </div>
                <div class="col-lg-5 col-md-5">
                    <div class="banner-img-con position-relative">
                        <figure><img src="{{ asset('assets/images/banner-robot.png')}}" alt="robot" class="animated-robot"></figure>
                        <div class="coment-box1 d-flex align-items-center popup-bubble popup-delay-1">
                            <img src="{{ asset('assets/images/coment-box-icon1.png')}}" alt="icon" class="img-fluid">
                            <p class="typing mb-0" id="text1"></p>
                            <!-- coment box1 -->
                        </div>
                        <div class="coment-box2 d-flex align-items-center popup-bubble popup-delay-2">
                            <img src="{{ asset('assets/images/coment-box-icon2.png')}}" alt="icon" class="img-fluid">
                            <p class="typing mb-0" id="text2"></p>
                            <!-- coment box1 -->
                        </div>
                        <!-- banner img con -->
                    </div>
                    <!-- col -->
                </div>
            </div>
            <div class="down_button text-center d-inline-block">
                <a href="#client" class="scroll text-decoration-none">
                    <figure class="banner-dropdownimage mb-0 d-inline-block">
                        <img src="{{ asset('assets/images/banner-dropdownimage.png')}}" class="img-fluid" alt="image">
                    </figure>
                </a>
            </div>
        </div>
    </section>

    <!-- CLIENT'S LOGO SECTION -->
    <section class="float-left w-100 amazing-features-con position-relative padding-top main-box">
        <div class="container wow fadeInUp" data-wow-duration="2s" data-wow-delay="0.2s">
            <div class="heading-title-con text-center">
                <span class="special-text color-blue d-block wow fadeInLeft" data-wow-duration="2s"
                      data-wow-delay="0.2s">Pensé pour les entreprises</span>
                <h2 class="wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.4s">
                    Ils nous font déjà confiance
                </h2>
                <!-- heading title con -->
            </div>
            <div class="row all_row wow fadeIn" data-wow-duration="2s" data-wow-delay="0.4s">
                <div class="float-left w-100 client-logo-con position-relative main-box" id="client">
                    <div class="container wow fadeInUp" data-wow-duration="2s" data-wow-delay="0.2s">
                        <div class="client-logo-inner d-flex align-items-center justify-content-between">
                            <p class="wow fadeInLeft" data-wow-duration="2s" data-wow-delay="0.2s">
                                Pour automatiser, centraliser et optimiser <br> leurs relations avec leurs clients
                            </p>
                            <div class="logos-con d-flex align-items-center justify-content-between wow fadeIn"
                                 data-wow-duration="2s" data-wow-delay="0.2s">
                                <figure><img src="https://drmaxisliterie.re/assets/img/logo_sticky.svg" alt="Dr. Maxis" class="img-fluid wow fadeInRight"
                                             data-wow-duration="2s" data-wow-delay="0.6s"></figure>
                                <figure><img src="https://inayya.re/wp-content/uploads/2026/02/logo-inayya-web.png" alt="Inayya" class="img-fluid wow fadeInRight"
                                             data-wow-duration="2s" data-wow-delay="1.0s"></figure>
                                <figure><img src="https://webenvue-mutualise-02.s3.eu-west-3.amazonaws.com/cm2s/2026/02/logo-cm2s-noir.svg" alt="CM2S" class="img-fluid wow fadeInRight"
                                             data-wow-duration="2s" data-wow-delay="1.4s"></figure>
                                <figure style="background-color: #000; padding: .15rem 0;"><img src="https://www.cuisinehabitat.mu/assets/images/logos/logo.svg" alt="Cuisine Habitat Maurice" class="img-fluid wow fadeInRight"
                                             data-wow-duration="2s" data-wow-delay="1.8s"></figure>
                                {{--<figure><img src="assets/images/client-logo5.png" alt="telegram" class="img-fluid wow fadeInRight"
                                             data-wow-duration="2s" data-wow-delay="2.2s"></figure>--}}
                            </div>
                            <!-- client logo inner -->
                        </div>
                        <!-- container -->
                    </div>
                    <!-- client logo -->
                </div>
                <!-- row -->
            </div>
            <!-- container -->
        </div>
    </section>

    
    <!-- AMAZING FEATURES SECTION -->
    <section class="float-left w-100 amazing-features-con position-relative padding-top padding-bottom main-box">
        <div class="container wow fadeInUp" data-wow-duration="2s" data-wow-delay="0.2s">
            <div class="heading-title-con text-center">
                <span class="special-text color-blue d-block wow fadeInLeft" data-wow-duration="2s"
                      data-wow-delay="0.2s">Les piliers d’ELChat</span>
                <h2 class="wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.4s">
                    De la connaissance métier<br> à l’exécution mesurable
                </h2>
                <!-- heading title con -->
            </div>
            <div class="row all_row wow fadeIn" data-wow-duration="2s" data-wow-delay="0.4s">
                <div class="col-lg-4 col-md-6 all_column wow fadeInLeft" data-wow-duration="2s" data-wow-delay="0.5s">
                    <div class="feature-box position-relative all_boxes">
                        <h4>Knowledge Intelligence & RAG</h4>
                        <p class="mb-0">
                            Indexez vos sites, documents, FAQ et catalogues dans une base de connaissances exploitable par l’IA.
                            ELChat retrouve le bon contexte, cite ses sources et identifie les informations à améliorer.
                        </p>
                        <img src="{{ asset('assets/images/feature-img1-icon1.png')}}" alt="feature image"
                             class="img-fluid position-absolute feature-icon1  wow fadeInUp" data-wow-duration="2s"
                             data-wow-delay="0.6s">

                        <figure><img src="{{ asset('assets/images/feature-img1.png')}}" alt="feature image"
                                     class="img-fluid  wow fadeInDown" data-wow-duration="2s" data-wow-delay="0.7s">
                        </figure>
                        <a href="{{ route('services.page') }}"><img src="{{ asset('assets/images/up-right-arrow.png')}}" alt="arrow"
                                                     class="img-fluid"></a>
                        <!-- feature box -->
                    </div>
                    <!-- col -->
                </div>
                <div class="col-lg-4 col-md-6 all_column wow fadeInUp" data-wow-duration="2s" data-wow-delay="0.5s">
                    <div class="feature-box position-relative all_boxes bg-green">
                        <h4>Workflows & connecteurs métier</h4>
                        <p class="mb-0">
                            Reliez CRM, e-commerce, agenda, stockage, marketing et outils d’équipe.
                            Vos workflows peuvent rechercher une information, préparer une action ou l’exécuter selon les permissions et validations définies.
                        </p>
                        <img src="{{ asset('assets/images/feature-img2-icon1.png')}}" alt="feature image"
                             class="img-fluid position-absolute feature-icon2  wow fadeInLeft" data-wow-duration="2s"
                             data-wow-delay="0.8s">
                        <img src="{{ asset('assets/images/feature-img2-icon2.png')}}" alt="feature image"
                             class="img-fluid position-absolute feature-icon3  wow fadeInRight" data-wow-duration="2s"
                             data-wow-delay="0.9s">
                        <img src="{{ asset('assets/images/feature-img2-icon3.png')}}" alt="feature image"
                             class="img-fluid position-absolute feature-icon4  wow fadeInLeft" data-wow-duration="2s"
                             data-wow-delay="1.0s">
                        <img src="{{ asset('assets/images/feature-img2-icon4.png')}}" alt="feature image"
                             class="img-fluid position-absolute feature-icon5 wow fadeInRight" data-wow-duration="2s"
                             data-wow-delay="1.1s">
                        <figure><img src="{{ asset('assets/images/feature-img2.png')}}" alt="feature image"
                                     class="img-fluid wow fadeInDown" data-wow-duration="2s" data-wow-delay="1.2s">
                        </figure>
                        <a href="{{ route('services.page')}}"><img src="{{ asset('assets/images/up-right-arrow.png')}}" alt="arrow"
                                                     class="img-fluid"></a>
                        <!-- feature box -->
                    </div>
                    <!-- col -->
                </div>
                <div class="col-lg-4 col-md-6 all_column  wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.5s">
                    <div class="feature-box position-relative all_boxes">
                        <h4>Business & Executive Intelligence</h4>
                        <p class="mb-0">
                            Transformez les événements clients, commerciaux et opérationnels en indicateurs lisibles.
                            ELChat aide à diagnostiquer une situation, prioriser la prochaine action et suivre son impact.
                        </p>
                        <img src="{{ asset('assets/images/feature-img3-icon1.png')}}" alt="feature image"
                             class="img-fluid position-absolute feature-icon6 wow fadeInUp" data-wow-duration="2s"
                             data-wow-delay="0.6s">
                        <img src="{{ asset('assets/images/elipse-blue.png')}}" alt="feature image"
                             class="img-fluid position-absolute blue-elipse wow fadeInDown" data-wow-duration="2s"
                             data-wow-delay="0.7s">
                        <figure><img src="{{ asset('assets/images/feature-img3.png')}}" alt="feature image"
                                     class="img-fluid feature-img3 wow fadeIn" data-wow-duration="2s" data-wow-delay="0.8s">
                        </figure>
                        <a href="{{ route('services.page')}}"><img src="{{ asset('assets/images/up-right-arrow.png')}}" alt="arrow"
                                                     class="img-fluid"></a>
                        <!-- feature box -->
                    </div>
                    <!-- col -->
                </div>
                <!-- row -->
            </div>
            <!-- container -->
        </div>
    </section>

    <!-- HOW IT WORKS SECTION -->
    <section class="float-left w-100 position-relative main-box how-it-works-con padding-top padding-bottom">
        <figure><img src="{{ asset('assets/images/vector3.png')}}" alt="vector"
                     class="img-fluid position-absolute vector3 animated-plane"></figure>
        <div class="container wow fadeInUp" data-wow-duration="2s" data-wow-delay="0.2s">
            <div class="row all_row">
                <div class="col-lg-7 col-md-12 wow fadeInLeft" data-wow-duration="2s" data-wow-delay="0.4s">
                    <div class="work-img-con position-relative">
                        <figure><img src="{{ asset('assets/images/work-img.png')}}" alt="image" class="img-fluid"></figure>
                        <figure><img src="{{ asset('assets/images/robot.png')}}" alt="robot"
                                     class="img-fluid position-absolute robot-img animated-robot">
                        </figure>
                    </div>
                    <!-- col -->
                </div>
                <div class="col-lg-5 col-md-12 wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.4s">
                    <div class="work-content-con">
                        <div class="heading-title-con">
                            <span class="special-text color-blue d-block wow fadeInLeft" data-wow-duration="2s"
                                  data-wow-delay="0.5s">Comment ça fonctionne</span>
                            <h2 class="wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.6s">
                                Connaître, agir et progresser en continu
                            </h2>
                            <!-- heading title con -->
                        </div>
                        <ul class="list-unstyled p-0">
                            <li class="position-relative d-flex align-items-center">
                                <span class="d-block color-blue">01</span>
                                <div class="work-content-inner-con">
                                    <h5>Connaître et comprendre</h5>
                                    <p class="mb-0">
                                        Centralisez les contenus et signaux utiles à votre activité.
                                        Le moteur RAG recherche le contexte pertinent pour répondre, analyser et éclairer la décision.
                                    </p>
                                    <!-- work content inner con -->
                                </div>
                            </li>
                            <li class="position-relative d-flex align-items-center">
                                <span class="d-block color-blue">02</span>
                                <div class="work-content-inner-con">
                                    <h5>Décider et agir</h5>
                                    <p class="mb-0">
                                        Déclenchez des workflows ou confiez un objectif à un agent spécialisé.
                                        Les actions sensibles restent soumises aux règles d’accès et aux confirmations prévues.
                                    </p>
                                    <!-- work content inner con -->
                                </div>
                            </li>
                            <li class="position-relative d-flex align-items-center">
                                <span class="d-block color-blue">03</span>
                                <div class="work-content-inner-con">
                                    <h5>Mesurer et apprendre</h5>
                                    <p class="mb-0">
                                        Suivez conversations, intentions, conversions, workflows et actions métier.
                                        Ces événements révèlent les points de friction et les connaissances à renforcer.
                                    </p>
                                    <!-- work content inner con -->
                                </div>
                            </li>
                        </ul>
                        <a href="{{ route('contact.page') }}" class="text-decoration-none primary_btn d-inline-block">
                            Étudier votre cas d’usage
                        </a>
                        <!-- work content con -->
                    </div>
                    <!-- col -->
                </div>
                <!--  -->
            </div>
            <!-- container -->
        </div>
        <!-- how it works con -->
    </section>

    <!-- PROACTIVE ENGAGEMENT SECTION -->
    <section class="float-left w-100 amazing-features-con position-relative padding-top padding-bottom main-box">
        <div class="container wow fadeInUp" data-wow-duration="2s" data-wow-delay="0.2s">
            <div class="heading-title-con text-center">
                <span class="special-text color-blue d-block wow fadeInLeft" data-wow-duration="2s" data-wow-delay="0.2s">Engagement Proactif</span>
                <h2 class="wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.4s">
                    Reprendre la conversation<br>au moment qui compte
                </h2>
                <p class="mx-auto" style="max-width: 760px;">
                    Lorsqu’une intention forte, une demande inachevée ou une opportunité est détectée, ELChat peut proposer une reprise contextualisée de la conversation. Les agents, workflows, quotas et validations du tenant restent aux commandes.
                </p>
            </div>
            <div class="row all_row wow fadeIn" data-wow-duration="2s" data-wow-delay="0.4s">
                <div class="col-lg-4 col-md-6 all_column wow fadeInLeft" data-wow-duration="2s" data-wow-delay="0.5s">
                    <div class="feature-box position-relative all_boxes">
                        <h4>Un message contextualisé</h4>
                        <p class="mb-0">ELChat s’appuie sur la mémoire, le résumé, les événements, le RAG et l’historique réel. Il ne complète pas les informations absentes.</p>
                    </div>
                </div>
                <div class="col-lg-4 col-md-6 all_column wow fadeInUp" data-wow-duration="2s" data-wow-delay="0.5s">
                    <div class="feature-box position-relative all_boxes bg-green">
                        <h4>Une reprise sans spam</h4>
                        <p class="mb-0">Cooldowns, horaires, quotas, permissions et arrêt après réponse ou conversion empêchent les relances indésirables.</p>
                    </div>
                </div>
                <div class="col-lg-4 col-md-6 all_column wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.5s">
                    <div class="feature-box position-relative all_boxes">
                        <h4>Un résultat mesurable</h4>
                        <p class="mb-0">Réponses, leads, rendez-vous, opportunités et ventes sont reliés à la séquence lorsque les données source le permettent.</p>
                    </div>
                </div>
            </div>
            <div class="float-left w-100 m-auto text-center wow fadeInUp" data-wow-duration="2s" data-wow-delay="0.4s">
                <a href="{{ route('contact.page') }}" class="text-decoration-none primary_btn d-inline-block">Parler d’un cas d’usage</a>
            </div>
        </div>
    </section>

    <!-- WHY CHOOSE US SECTION -->
    <section class="float-left w-100 position-relative why-choose-us-con padding-top padding-bottom main-box">
        <div class="container wow fadeInUp" data-wow-duration="2s" data-wow-delay="0.2s">
            <div class="heading-title-con text-center">
                <span class="special-text color-blue d-block wow fadeInLeft" data-wow-duration="2s"
                      data-wow-delay="0.2s">Pourquoi ELChat</span>
                <h2 class="wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.4s">
                    Une plateforme conçue pour relier<br> intelligence, contrôle et exécution
                </h2>
                <!-- heading title con -->
            </div>
            <div class="choose-outer-con wow fadeInDown" data-wow-duration="2s" data-wow-delay="0.5s">
                <div class="choose-box">
                    <figure><img src="{{ asset('assets/images/choose-icon1.png')}}" alt="icon" class="img-fluid"></figure>
                    <h6>Un socle de connaissance fiable</h6>
                    <p class="mb-0">
                        Sites, documents, FAQ et produits alimentent un contexte propre à votre entreprise, avec recherche hybride, sources et suivi de qualité.
                    </p>
                    <!-- choose box -->
                </div>
                <div class="choose-box">
                    <figure><img src="{{ asset('assets/images/choose-icon2.png')}}" alt="icon" class="img-fluid"></figure>
                    <h6>Des outils métier connectés</h6>
                    <p class="mb-0">
                        Connectez vos canaux clients, CRM, boutiques, agendas, espaces documentaires et plateformes marketing sans multiplier les systèmes isolés.
                    </p>
                    <!-- choose box -->
                </div>
                <div class="choose-box">
                    <figure><img src="{{ asset('assets/images/choose-icon3.png')}}" alt="icon" class="img-fluid"></figure>
                    <h6>Une autonomie sous contrôle</h6>
                    <p class="mb-0">
                        Définissez ce que l’IA peut lire, proposer ou exécuter. Les permissions, confirmations et journaux d’audit encadrent chaque action sensible.
                    </p>
                    <!-- choose box -->
                </div>
                <div class="choose-box">
                    <figure><img src="{{ asset('assets/images/choose-icon4.png')}}" alt="icon" class="img-fluid"></figure>
                    <h6>Une adoption progressive</h6>
                    <p class="mb-0">
                        Commencez avec le socle Core, puis ajoutez l’omnicanal, l’automatisation métier ou les agents spécialisés selon vos priorités.
                    </p>
                    <!-- choose box -->
                </div>
                <div class="choose-box">
                    <figure><img src="{{ asset('assets/images/choose-icon5.png')}}" alt="icon" class="img-fluid"></figure>
                    <h6>Au-delà du chatbot</h6>
                    <p class="mb-0">
                        ELChat ne se limite pas à converser : la plateforme analyse des événements, coordonne des workflows et agit dans les outils connectés.
                    </p>
                    <!-- choose box -->
                </div>
                <!-- choose outer con -->
            </div>
            <div class="float-left w-100 m-auto text-center wow fadeInUp" data-wow-duration="2s" data-wow-delay="0.4s">
                <a href="{{ route('about.page')}}" class="text-decoration-none primary_btn d-inline-block">Comprendre notre approche</a>
            </div>
            <!-- container -->
        </div>
        <!-- why choose us  -->
    </section>

    <!-- PRICING PLAN SECTION -->
    <section class="float-left w-100 position-relative pricing-plan-con padding-top padding-bottom main-box">
        <div class="container wow fadeInUp" data-wow-duration="2s" data-wow-delay="0.2s">
            <div class="heading-title-con text-center">
                <span class="special-text color-blue d-block wow fadeInLeft" data-wow-duration="2s"
                      data-wow-delay="0.4s">Tarification</span>
                <h2 class="wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.5s">
                    Un socle commun, puis les capacités<br> utiles à votre organisation
                </h2>
                <p> Core démarre à 29 € par mois. Ajoutez ensuite Community, Business Automation ou Agentics selon vos usages ; l’offre Agency est étudiée sur devis. </p>
                <!-- heading title con -->
            </div>
            <div class="row all_row wow fadeInDown" data-wow-duration="2s" data-wow-delay="0.5s">
                <div class="col-lg-4 col-md-6 all_column">
                    <div class="pricing-box w-100 all_boxes">
                        <div class="plan-content">
                            <h3 class="">Core</h3>
                            <p>
                                Le socle indispensable pour structurer et exploiter la connaissance de votre entreprise.
                            </p>
                            <div class="generic-price d-inline-block">
                                <span class="d-block  starting-at">
                                    À partir de :
                                </span>
                                <sup class="d-inline-block  font-weight-normal">€</sup>
                                <span class="d-inline-block  price-text font-weight-600">29</span>
                                <span class="d-inline-block  per-month mb-0 position-relative font-weight-normal">
                                    /mois
                                </span>
                            </div>
                        </div>
                        <div class="plan-listing">
                            <ul class="list-unstyled p-0 ">
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Base de connaissances et RAG
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Site, documents, FAQ et produits
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Widget et réponses contextualisées
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Indexation et sources
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Suivi de la qualité des connaissances
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Historique et analytique d’usage
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    À partir de 29 € / mois
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Capacités additionnelles au choix
                                </li>

                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Même plateforme, sans changement de socle
                                </li>
                            </ul>
                            <a href="{{ route('contact.page') }}" class="text-decoration-none primary_btn">Parler de votre besoin</a>
                        </div>
                    </div>
                </div>
                <!-- 🟡 BASIC (NOUVEAU BLOC) -->
                <div class="col-lg-4 col-md-6 all_column">
                    <div class="el-default-pricing pricing-box w-100 all_boxes">
                        <div class="plan-content">
                            <h3 class="">Extensions</h3>
                            <p>
                                Ajoutez uniquement les capacités omnicanales et métier dont vos équipes ont besoin.
                            </p>
                            <div class="generic-price d-inline-block">
                                <span class="d-block starting-at">À partir de :</span>
                                <sup class="d-inline-block font-weight-normal">€</sup>
                                <span class="d-inline-block price-text font-weight-600">19</span>
                                <span class="d-inline-block per-month mb-0 position-relative font-weight-normal">
                                    /mois
                                </span>
                            </div>
                        </div>

                        <div class="plan-listing">
                            <ul class="list-unstyled p-0 ">
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Community Basic dès +19 € / mois
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Community Pro à +49 € / mois
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Canaux sociaux, messagerie et e-mail
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Business Automation Basic dès +39 € / mois
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Business Automation Pro à +99 € / mois
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Workflows et connecteurs métier
                                </li>
                            </ul>
                            <a href="{{ route('contact.page') }}" class="text-decoration-none primary_btn">Composer votre solution</a>
                        </div>
                    </div>
                </div>
                <!-- 🔵 PRO -->
                <div class="col-lg-4 col-md-6 all_column">
                    <div class="pricing-box w-100 all_boxes">
                        <div class="plan-content">
                            <h3 class="">Agentics</h3>
                            <p>
                                Déployez des agents IA spécialisés pour des objectifs commerciaux et opérationnels précis.
                            </p>
                            <div class="generic-price d-inline-block">
                                <span class="d-block starting-at">
                                    À partir de :
                                </span>
                                <sup class="d-inline-block font-weight-normal">€</sup>
                                <span class="d-inline-block price-text font-weight-600">59</span>
                                <span class="d-inline-block per-month mb-0 position-relative font-weight-normal">
                                    /mois
                                </span>
                            </div>
                        </div>

                        <div class="plan-listing">
                            <ul class="list-unstyled p-0 ">
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Agentics Basic dès +59 € / mois
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Agentics Pro à +149 € / mois
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Banque d’agents spécialisés
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Workflows coordonnés et permissions
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    AI Sales Hunter disponible
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Offre Agency en marque blanche sur devis
                                </li>
                            </ul>
                            <a href="{{ route('contact.page') }}" class="text-decoration-none primary_btn">Évaluer les agents</a>
                        </div>
                    </div>
                </div>
            </div>
            <!-- container -->
        </div>
        <!-- pricing plan con -->
    </section>

    <!-- FAQ'S SECTION -->
    <section class="faq-con position-relative float-left w-100 main-box padding-top">
        <figure><img src="{{ asset('assets/images/vector1.png')}}" alt="vector"
                     class="img-fluid position-absolute vector1 animated-plane"></figure>
        <figure><img src="{{ asset('assets/images/vector2.png')}}" alt="vector" class="img-fluid position-absolute vector2"></figure>
        <div class="container wow fadeInUp" data-wow-duration="2s" data-wow-delay="0.2s">
            <div class="row ">
                <div class="col-xl-7 col-lg-10 col-12 mx-auto">
                    <div class="faq_content text-center">
                        <span class="special-text color-blue d-block wow fadeInLeft" data-wow-duration="2s"
                      data-wow-delay="0.2s">Questions fréquentes</span>
                        <h2 class=" wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.4s">
                            Les réponses essentielles avant<br> de déployer ELChat
                        </h2>
                    </div>
                </div>
            </div>
            <div class="faq wow fadeInDown" data-wow-duration="2s" data-wow-delay="0.2s">
                <div class="accordian-section-inner position-relative">
                    <div class="accordian-inner">
                        <div id="faq_accordion1">
                            <div class="row">
                                <div class="col-xl-8 col-lg-10 col-md-12 col-sm-12 col-12 mx-auto">
                                    <div class="accordion-card">
                                        <div class="card-header" id="headingOne">
                                            <a href="#" class="btn btn-link collapsed" data-toggle="collapse"
                                               data-target="#collapseOne" aria-expanded="false"
                                               aria-controls="collapseOne">
                                                <h6>
                                                    ELChat est-il un chatbot ?
                                                </h6>
                                            </a>
                                        </div>
                                        <div id="collapseOne" class="collapse" aria-labelledby="headingOne"
                                             data-parent="#faq_accordion1">
                                            <div class="card-body">
                                                <p class="text-size-16 text-left mb-0">
                                                    ELChat inclut un assistant conversationnel, mais son périmètre est plus large : connaissances, événements, connecteurs, workflows et agents spécialisés sont réunis pour aider l’entreprise à comprendre, décider et agir.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="accordion-card">
                                        <div class="card-header" id="headingTwo">
                                            <a href="#" class="btn btn-link collapsed" data-toggle="collapse"
                                               data-target="#collapseTwo" aria-expanded="false"
                                               aria-controls="collapseTwo">
                                                <h6>
                                                    Comment ELChat utilise-t-il nos données ?
                                                </h6>
                                            </a>
                                        </div>
                                        <div id="collapseTwo" class="show collapse" aria-labelledby="headingTwo"
                                             data-parent="#faq_accordion1">
                                            <div class="card-body">
                                                <p class="text-size-16 text-left mb-0">
                                                    Les contenus importés ou indexés sont découpés et recherchés selon leur pertinence. ELChat utilise ce contexte pour produire une réponse ou une analyse reliée aux connaissances de votre entreprise, avec les sources disponibles.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="accordion-card">
                                        <div class="card-header" id="headingThree">
                                            <a href="#" class="btn btn-link collapsed" data-toggle="collapse"
                                               data-target="#collapseThree" aria-expanded="false"
                                               aria-controls="collapseThree">
                                                <h6>ELChat peut-il agir dans nos outils ?</h6>
                                            </a>
                                        </div>
                                        <div id="collapseThree" class="collapse" aria-labelledby="headingThree"
                                             data-parent="#faq_accordion1">
                                            <div class="card-body">
                                                <p class="text-size-16 text-left mb-0">
                                                    Oui, par l’intermédiaire de connecteurs et de workflows. Les droits précisent ce que l’IA peut consulter, proposer ou exécuter, et les actions sensibles peuvent exiger une confirmation humaine.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="accordion-card">
                                        <div class="card-header" id="headingFour">
                                            <a href="#" class="btn btn-link collapsed" data-toggle="collapse"
                                               data-target="#collapseFour" aria-expanded="false"
                                               aria-controls="collapseFour">
                                                <h6>Comment fonctionne la tarification modulaire ?</h6>
                                            </a>
                                        </div>
                                        <div id="collapseFour" class="collapse" aria-labelledby="headingFour"
                                             data-parent="#faq_accordion1">
                                            <div class="card-body">
                                                <p class="text-size-16 text-left mb-0">
                                                    Core constitue le socle à 29 € par mois. Community, Business Automation et Agentics s’ajoutent au niveau Basic ou Pro ; l’offre Agency est proposée sur devis. Vous conservez la même plateforme et activez les capacités utiles.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
