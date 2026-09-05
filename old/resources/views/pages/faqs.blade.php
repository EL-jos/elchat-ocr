@extends('pages.layouts.blank')

@section('seo')
    <!-- Primary Meta Tags -->
    <title>FAQ ELChat | Engagement proactif, RAG, workflows et agents IA</title>

    <meta name="title" content="FAQ ELChat | Engagement proactif, RAG, workflows et agents IA">

    <meta name="description"
          content="Réponses sur ELChat : engagement proactif, RAG, connecteurs, workflows, agents spécialisés, contrôle humain, tarification modulaire et déploiement.">

    <meta name="keywords"
          content="FAQ ELChat, engagement proactif, relance contextuelle, RAG entreprise, workflows IA, agents IA, connecteurs métier, tarification modulaire, automatisation entreprise, plateforme IA opérationnelle">

    <meta name="author" content="ELChat">
    <meta name="robots" content="index, follow">

    <link rel="canonical" href="https://elchat.io/faqs">

    <!-- Open Graph -->
    <meta property="og:type" content="website">
    <meta property="og:locale" content="fr_FR">
    <meta property="og:site_name" content="ELChat">

    <meta property="og:title"
          content="FAQ ELChat | Tout savoir sur la plateforme d'IA opérationnelle">

    <meta property="og:description"
          content="Découvrez comment ELChat connecte les connaissances et les outils, encadre les actions de l'IA et s'adapte aux besoins de l'entreprise.">

    <meta property="og:url"
          content="https://elchat.io/faqs">

    <meta property="og:image"
          content="https://elchat.io/assets/images/sub-banner-img.png">

    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">

    <meta name="twitter:title"
          content="FAQ ELChat">

    <meta name="twitter:description"
          content="Réponses aux questions fréquentes sur les connaissances, workflows, engagement proactif, connecteurs, agents et tarifs ELChat.">

    <meta name="twitter:image"
          content="https://elchat.io/assets/images/sub-banner-img.png">
    
@endsection

@section('main-content')

    <!-- SUB BANNER SECTION -->
    <section class="float-left w-100 sub-banner-con position-relative main-box">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-7 col-md-7">
                    <div class="sub-banner-content-con">
                        <h1>Questions fréquentes</h1>
                        <p>
                            Positionnement, données, intégrations, automatisations et tarifs : les réponses utiles pour évaluer ELChat dans le contexte de votre entreprise.
                        </p>
                        <div class="breadcrumb-con d-inline-block">
                            <ol class="breadcrumb mb-0">
                                <li class="breadcrumb-item"><a href="{{ route('home.page') }}">Accueil</a></li>
                                <li class="breadcrumb-item active" aria-current="page">FAQ</li>
                            </ol>
                        </div>
                        <!-- sub banner content con -->
                    </div>

                    <!-- col -->
                </div>
                <div class="col-lg-5 col-md-5">
                    <div class="sub-banner-img-con">
                        <figure>
                            <img src="{{ asset('assets/images/sub-banner-img.png')}}" alt="robot">
                        </figure>
                        <!-- sub banner img con -->
                    </div>
                    <!-- col -->
                </div>
                <!-- row -->
            </div>
            <!-- container -->
        </div>
        <!-- sub banner con -->
    </section>

    <!-- FAQ'S SECTION -->
    <section class="faq-con position-relative float-left w-100 main-box padding-top padding-bottom">
        <div class="container wow fadeInUp" data-wow-duration="2s" data-wow-delay="0.2s">
            <div class="row ">
                <div class="col-xl-7 col-lg-10 col-12 mx-auto">
                    <div class="faq_content text-center">
                        <span class="special-text color-blue d-block wow fadeInLeft" data-wow-duration="2s"
                              data-wow-delay="0.2s">FAQ</span>
                        <h2 class=" wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.4s">Comprendre ELChat<br>
                            avant de le <span>déployer</span></h2>
                    </div>
                </div>
            </div>
            <div class="faq">
                <div class="accordian-section-inner position-relative">
                    <div class="accordian-inner">
                        <div id="faq_accordion1">
                            <div class="row">
                                <div class="col-xl-6 col-lg-6 col-md-12 col-sm-12 col-12 mx-auto wow fadeInLeft"
                                     data-wow-duration="2s" data-wow-delay="0.2s">
                                    <div class="accordion-card">
                                        <div class="card-header" id="headingOne">
                                            <a href="#" class="btn btn-link collapsed" data-toggle="collapse"
                                               data-target="#collapseOne" aria-expanded="false"
                                               aria-controls="collapseOne">
                                                <h5>
                                                    ELChat est-il un chatbot ou une plateforme d’IA ?
                                                </h5>
                                            </a>
                                        </div>
                                        <div id="collapseOne" class="collapse" aria-labelledby="headingOne"
                                             data-parent="#faq_accordion1">
                                            <div class="card-body">
                                                <p class="text-size-16 text-left mb-0">
                                                    ELChat est une plateforme d’IA opérationnelle. Elle comprend un assistant conversationnel, mais aussi une base de connaissances RAG, des événements, des connecteurs métier, des workflows, des analyses et des agents spécialisés.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="accordion-card">
                                        <div class="card-header" id="headingTwo">
                                            <a href="#" class="btn btn-link collapsed" data-toggle="collapse"
                                               data-target="#collapseTwo" aria-expanded="false"
                                               aria-controls="collapseTwo">
                                                <h5>
                                                    Comment fonctionne la base de connaissances RAG ?
                                                </h5>
                                            </a>
                                        </div>
                                        <div id="collapseTwo" class="collapse" aria-labelledby="headingTwo"
                                             data-parent="#faq_accordion1">
                                            <div class="card-body">
                                                <p class="text-size-16 text-left mb-0">
                                                    Les contenus de vos sites, documents, FAQ et produits sont indexés en fragments recherchables. Lors d’une demande, ELChat sélectionne le contexte pertinent pour répondre ou analyser à partir de vos propres informations, avec les sources disponibles.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="accordion-card">
                                        <div class="card-header" id="headingThree">
                                            <a href="#" class="btn btn-link collapsed" data-toggle="collapse"
                                               data-target="#collapseThree" aria-expanded="false"
                                               aria-controls="collapseThree">
                                                <h5>Comment se compose l’abonnement ELChat ?</h5>
                                            </a>
                                        </div>
                                        <div id="collapseThree" class="collapse" aria-labelledby="headingThree"
                                             data-parent="#faq_accordion1">
                                            <div class="card-body">
                                                <p class="text-size-16 text-left mb-0">
                                                    <strong>Core :</strong> le socle obligatoire à 29 € par mois.<br><br>
                                                    <strong>Community :</strong> Basic +19 € ou Pro +49 € par mois.<br><br>
                                                    <strong>Business Automation :</strong> Basic +39 € ou Pro +99 € par mois.<br><br>
                                                    <strong>Agentics :</strong> Basic +59 € ou Pro +149 € par mois. Agency est proposé sur devis.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="accordion-card">
                                        <div class="card-header" id="headingFour">
                                            <a href="#" class="btn btn-link collapsed" data-toggle="collapse"
                                               data-target="#collapseFour" aria-expanded="false"
                                               aria-controls="collapseFour">
                                                <h5>Quels canaux d’engagement sont disponibles ?</h5>
                                            </a>
                                        </div>
                                        <div id="collapseFour" class="collapse" aria-labelledby="headingFour"
                                             data-parent="#faq_accordion1">
                                            <div class="card-body">
                                                <p class="text-size-16 text-left mb-0">
                                                    ELChat dispose d’intégrations pour le widget web, Facebook, Instagram, YouTube, Telegram, Slack et l’e-mail. Les canaux réellement activables dépendent du module choisi et de la configuration de votre compte.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="accordion-card">
                                        <div class="card-header" id="headingProactive">
                                            <a href="#" class="btn btn-link collapsed" data-toggle="collapse"
                                               data-target="#collapseProactive" aria-expanded="false"
                                               aria-controls="collapseProactive">
                                                <h5>ELChat peut-il reprendre une conversation avec un visiteur ?</h5>
                                            </a>
                                        </div>
                                        <div id="collapseProactive" class="collapse" aria-labelledby="headingProactive"
                                             data-parent="#faq_accordion1">
                                            <div class="card-body">
                                                <p class="text-size-16 text-left mb-0">
                                                    Oui. L’Engagement Proactif détecte un signal pertinent, comme une demande de devis inachevée ou une intention commerciale forte, puis peut proposer une reprise dans la conversation existante. Le message est contextualisé par les données disponibles et reste soumis aux quotas, horaires, permissions, règles d’arrêt et choix du visiteur. ELChat ne crée pas de relances illimitées et ne promet pas de résultat absent des données observées.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-xl-6 col-lg-6 col-md-12 col-sm-12 col-12 mx-auto wow fadeInRight"
                                     data-wow-duration="2s" data-wow-delay="0.4s">
                                    <div class="accordion-card">
                                        <div class="card-header" id="heading5">
                                            <a href="#" class="btn btn-link collapsed" data-toggle="collapse"
                                               data-target="#collapse5" aria-expanded="false"
                                               aria-controls="collapse5">
                                                <h5>
                                                    Quelles sources et quels outils peut-on connecter ?
                                                </h5>
                                            </a>
                                        </div>
                                        <div id="collapse5" class="collapse" aria-labelledby="heading5"
                                             data-parent="#faq_accordion1">
                                            <div class="card-body">
                                                <p class="text-size-16 text-left mb-0">
                                                    Vous pouvez indexer un site ou un sitemap, importer des documents et contenus, et synchroniser des données produit. Le catalogue métier couvre notamment CRM, e-commerce, agendas, stockage, collaboration, marketing et analytique.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="accordion-card">
                                        <div class="card-header" id="heading6">
                                            <a href="#" class="btn btn-link collapsed" data-toggle="collapse"
                                               data-target="#collapse6" aria-expanded="false"
                                               aria-controls="collapse6">
                                                <h5>
                                                    ELChat peut-il exécuter des actions automatiquement ?
                                                </h5>
                                            </a>
                                        </div>
                                        <div id="collapse6" class="collapse" aria-labelledby="heading6"
                                             data-parent="#faq_accordion1">
                                            <div class="card-body">
                                                <p class="text-size-16 text-left mb-0">
                                                    Oui, lorsqu’un workflow, un connecteur et les permissions nécessaires sont configurés. Selon le risque, l’action peut être autorisée, bloquée ou placée en attente d’une confirmation humaine avant exécution.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="accordion-card">
                                        <div class="card-header" id="heading7">
                                            <a href="#" class="btn btn-link collapsed" data-toggle="collapse"
                                               data-target="#collapse7" aria-expanded="false"
                                               aria-controls="collapse7">
                                                <h5>ELChat apprend-il automatiquement avec le temps ?</h5>
                                            </a>
                                        </div>
                                        <div id="collapse7" class="collapse" aria-labelledby="heading7"
                                             data-parent="#faq_accordion1">
                                            <div class="card-body">
                                                <p class="text-size-16 text-left mb-0">
                                                    ELChat ne réentraîne pas seul un modèle sur vos échanges. La qualité progresse lorsque vous mettez à jour les sources, réindexez les contenus et utilisez les indicateurs de qualité pour combler les lacunes de connaissance.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="accordion-card">
                                        <div class="card-header" id="heading8">
                                            <a href="#" class="btn btn-link collapsed" data-toggle="collapse"
                                               data-target="#collapse8" aria-expanded="false"
                                               aria-controls="collapse8">
                                                <h5>Comment ELChat encadre-t-il les accès et les actions ?</h5>
                                            </a>
                                        </div>
                                        <div id="collapse8" class="collapse" aria-labelledby="heading8"
                                             data-parent="#faq_accordion1">
                                            <div class="card-body">
                                                <p class="text-size-16 text-left mb-0">
                                                    Les données et connecteurs sont rattachés au compte et au site concernés. Les permissions, confirmations et journaux d’audit limitent l’accès aux outils et rendent les actions traçables. Les exigences propres à votre organisation doivent être validées lors du cadrage.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="accordion-card">
                                        <div class="card-header" id="heading9">
                                            <a href="#" class="btn btn-link collapsed" data-toggle="collapse"
                                               data-target="#collapse9" aria-expanded="false"
                                               aria-controls="collapse9">
                                                <h5>Comment démarrer sans tout automatiser immédiatement ?</h5>
                                            </a>
                                        </div>
                                        <div id="collapse9" class="collapse" aria-labelledby="heading9"
                                             data-parent="#faq_accordion1">
                                            <div class="card-body">
                                                <p class="text-size-16 text-left mb-0">
                                                    Commencez par Core et un périmètre de connaissance précis. Ajoutez ensuite un canal, un workflow ou un agent, testez les résultats, puis élargissez l’autonomie une fois les règles et validations établies.
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

    <!-- STATISTICS SECTION -->
    <section class="float-left w-100 statistics-con position-relative padding-top padding-bottom main-box">
        <div class="container">
            <div class="row">
                <div class="col-lg-6 col-md-6 wow fadeInLeft" data-wow-duration="2s" data-wow-delay="0.2s">
                    <div class="statistics-content-con">
                        <div class="heading-title-con mb-0">
                            <span class="special-text color-blue d-block wow fadeInLeft" data-wow-duration="2s"
                                  data-wow-delay="0.4s">Déploiement progressif</span>
                            <h2 class="wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.5s">
                                Commencez par un besoin,<br>
                                étendez selon les résultats
                            </h2>
                            <p class="wow fadeInLeft p-0" data-wow-duration="2s" data-wow-delay="0.6s">
                                Cadrez d’abord les sources, les utilisateurs et les actions autorisées. Déployez ensuite
                                les capacités par étapes, mesurez les événements utiles et enrichissez la connaissance
                                avant d’augmenter le niveau d’autonomie.
                            </p>

                            <a href="about.html" class="text-decoration-none primary_btn d-inline-block wow
                                fadeInDown" data-wow-duration="2s" data-wow-delay="0.6s">Préparer votre cadrage</a>
                            <!-- heading title con -->
                        </div>
                        <!-- statistics content con -->
                    </div>
                    <!-- col -->
                </div>
                <div class="col-lg-6 col-md-6 wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.2s">
                    <div class="statistics-outer-con">
                        <div class="row">
                            <div class="col-lg-6 col-md-6 d-flex">
                                <div class="statistics-box w-100">
                                    <figure><img src="{{ asset('assets/images/statistics-icon1.png')}}" alt="icon" class="img-fluid">
                                    </figure>
                                    <span class="d-inline-block black-text counter">1 </span><sup
                                        class="d-inline-block black-text"></sup>
                                    <span class="span-text d-block">Socle Core commun</span>
                                    <!-- statistics box -->
                                </div>
                                <!-- col -->
                            </div>
                            <div class="col-lg-6 col-md-6 d-flex">
                                <div class="statistics-box w-100">
                                    <figure><img src="{{ asset('assets/images/statistics-icon2.png')}}" alt="icon" class="img-fluid">
                                    </figure>
                                    <span class="d-inline-block black-text">2 </span>
                                    <!-- <span class="d-inline-block alphabet black-text">k</span> -->
                                    <span class="span-text d-block">Niveaux Basic et Pro</span>
                                    <!-- statistics box -->
                                </div>
                                <!-- col -->
                            </div>
                            <div class="col-lg-6 col-md-6 d-flex">
                                <div class="statistics-box w-100">
                                    <figure><img src="{{ asset('assets/images/statistics-icon3.png')}}" alt="icon" class="img-fluid">
                                    </figure>
                                    <sup class="d-inline-block black-text"></sup><span
                                        class="d-inline-block black-text counter">3 </span><sup
                                        class="d-inline-block black-text"></sup>
                                    <span class="span-text d-block">Familles de modules optionnels</span>
                                    <!-- statistics box -->
                                </div>
                                <!-- col -->
                            </div>
                            <div class="col-lg-6 col-md-6 d-flex">
                                <div class="statistics-box w-100">
                                    <figure><img src="{{ asset('assets/images/statistics-icon4.png')}}" alt="icon" class="img-fluid">
                                    </figure>
                                    <span class="d-inline-block black-text counter">6 </span><sup
                                        class="d-inline-block black-text"></sup>
                                    <span class="span-text d-block">Étapes de la boucle opérationnelle</span>
                                    <!-- statistics box -->
                                </div>
                                <!-- col -->
                            </div>
                            <!-- row -->
                        </div>
                        <!-- statistics outer con  -->
                    </div>
                </div>

                <!-- row -->
            </div>
        </div>
        <!-- statistics con -->
    </section>

@endsection
