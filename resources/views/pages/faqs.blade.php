@extends('pages.layouts.blank')

@section('seo')
    <!-- Primary Meta Tags -->
    <title>FAQ ELChat | Questions fréquentes sur la plateforme d'IA conversationnelle</title>

    <meta name="title" content="FAQ ELChat | Questions fréquentes sur la plateforme d'IA conversationnelle">

    <meta name="description"
          content="Trouvez les réponses aux questions fréquentes sur ELChat : fonctionnement, intégrations, IA conversationnelle, automatisation des conversations, connexion aux réseaux sociaux et utilisation de la plateforme.">

    <meta name="keywords"
          content="FAQ ELChat, questions ELChat, IA conversationnelle, chatbot entreprise, automatisation conversations, support client IA, intégration Instagram IA, YouTube automation, plateforme IA entreprise, knowledge base AI">

    <meta name="author" content="ELChat">
    <meta name="robots" content="index, follow">

    <link rel="canonical" href="https://elchat.io/faqs">

    <!-- Open Graph -->
    <meta property="og:type" content="website">
    <meta property="og:locale" content="fr_FR">
    <meta property="og:site_name" content="ELChat">

    <meta property="og:title"
          content="FAQ ELChat | Tout savoir sur la plateforme d'IA conversationnelle">

    <meta property="og:description"
          content="Découvrez comment ELChat fonctionne, comment connecter vos données et comment automatiser vos conversations grâce à une IA alimentée par votre entreprise.">

    <meta property="og:url"
          content="https://elchat.io/faqs">

    <meta property="og:image"
          content="https://elchat.io/assets/images/sub-banner-img.png">

    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">

    <meta name="twitter:title"
          content="FAQ ELChat">

    <meta name="twitter:description"
          content="Réponses aux questions fréquentes sur ELChat et son fonctionnement basé sur l'IA conversationnelle.">

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
                            Tout ce que vous devez savoir sur ELChat et sur la façon dont notre plateforme
                            transforme vos connaissances d’entreprise en conversations intelligentes automatisées.
                            Découvrez comment connecter vos données, vos réseaux sociaux et vos clients dans un seul écosystème conversationnel.
                        </p>
                        <div class="breadcrumb-con d-inline-block">
                            <ol class="breadcrumb mb-0">
                                <li class="breadcrumb-item"><a href="{{ route('home.page') }}">Accueil</a></li>
                                <li class="breadcrumb-item active" aria-current="page">FAQ's</li>
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
                              data-wow-delay="0.2s">Faq's</span>
                        <h2 class=" wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.4s">Answers to Your
                            Most
                            Frequently Asked <span>Questions</span></h2>
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
                                                    Qu’est-ce qu’ELChat exactement ?
                                                </h5>
                                            </a>
                                        </div>
                                        <div id="collapseOne" class="collapse" aria-labelledby="headingOne"
                                             data-parent="#faq_accordion1">
                                            <div class="card-body">
                                                <p class="text-size-16 text-left mb-0">
                                                    ELChat est une plateforme d’IA conversationnelle qui transforme les connaissances de votre entreprise (site web, documents, FAQ, produits) en conversations automatisées sur vos canaux sociaux et digitaux.
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
                                                    Comment ELChat génère ses réponses ?
                                                </h5>
                                            </a>
                                        </div>
                                        <div id="collapseTwo" class="collapse" aria-labelledby="headingTwo"
                                             data-parent="#faq_accordion1">
                                            <div class="card-body">
                                                <p class="text-size-16 text-left mb-0">
                                                    ELChat combine plusieurs couches d’intelligence : vos connaissances (chunks issus de vos données),
                                                    l’historique des conversations, et un modèle d’IA avancé.
                                                    Chaque réponse est générée en tenant compte du contexte réel de votre entreprise.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="accordion-card">
                                        <div class="card-header" id="headingThree">
                                            <a href="#" class="btn btn-link collapsed" data-toggle="collapse"
                                               data-target="#collapseThree" aria-expanded="false"
                                               aria-controls="collapseThree">
                                                <h5>Quelle est la différence entre messages, tokens et chunks ?</h5>
                                            </a>
                                        </div>
                                        <div id="collapseThree" class="collapse" aria-labelledby="headingThree"
                                             data-parent="#faq_accordion1">
                                            <div class="card-body">
                                                <p class="text-size-16 text-left mb-0">
                                                    <strong>Messages :</strong> chaque interaction utilisateur (commentaire, DM, réponse).<br><br>
                                                    <strong>Tokens :</strong> unité de calcul utilisée par l’IA pour générer et comprendre les réponses.<br><br>
                                                    <strong>Chunks :</strong> morceaux de vos données (site web, documents, FAQ) transformés en base de connaissances exploitable par l’IA.<br><br>
                                                    ELChat relie ces trois éléments pour produire des réponses précises et contextualisées.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="accordion-card">
                                        <div class="card-header" id="headingFour">
                                            <a href="#" class="btn btn-link collapsed" data-toggle="collapse"
                                               data-target="#collapseFour" aria-expanded="false"
                                               aria-controls="collapseFour">
                                                <h5>Quels canaux sont supportés par ELChat ?</h5>
                                            </a>
                                        </div>
                                        <div id="collapseFour" class="collapse" aria-labelledby="headingFour"
                                             data-parent="#faq_accordion1">
                                            <div class="card-body">
                                                <p class="text-size-16 text-left mb-0">
                                                    ELChat s’intègre avec les principaux canaux d’engagement : Instagram, YouTube, Facebook, et bientôt TikTok, LinkedIn et WhatsApp.
                                                    Toutes les interactions sont centralisées dans une seule plateforme.
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
                                                    Est-ce que je peux connecter mes propres données ?
                                                </h5>
                                            </a>
                                        </div>
                                        <div id="collapse5" class="collapse" aria-labelledby="heading5"
                                             data-parent="#faq_accordion1">
                                            <div class="card-body">
                                                <p class="text-size-16 text-left mb-0">
                                                    Oui. Vous pouvez connecter votre site web, importer des documents (PDF, DOCX, CSV), ajouter des FAQ et synchroniser vos produits.
                                                    ELChat construit automatiquement votre base de connaissances.
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
                                                    ELChat répond-il automatiquement aux clients ?
                                                </h5>
                                            </a>
                                        </div>
                                        <div id="collapse6" class="collapse" aria-labelledby="heading6"
                                             data-parent="#faq_accordion1">
                                            <div class="card-body">
                                                <p class="text-size-16 text-left mb-0">
                                                    Oui. ELChat peut répondre automatiquement aux commentaires et messages,
                                                    tout en respectant vos règles métier, votre ton de marque et vos instructions personnalisées.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="accordion-card">
                                        <div class="card-header" id="heading7">
                                            <a href="#" class="btn btn-link collapsed" data-toggle="collapse"
                                               data-target="#collapse7" aria-expanded="false"
                                               aria-controls="collapse7">
                                                <h5>Est-ce que ELChat apprend avec le temps ?</h5>
                                            </a>
                                        </div>
                                        <div id="collapse7" class="collapse" aria-labelledby="heading7"
                                             data-parent="#faq_accordion1">
                                            <div class="card-body">
                                                <p class="text-size-16 text-left mb-0">
                                                    Non. ELChat améliore ses réponses en analysant les contenus de votre entreprise ajoutés à sa base de connaissance.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="accordion-card">
                                        <div class="card-header" id="heading8">
                                            <a href="#" class="btn btn-link collapsed" data-toggle="collapse"
                                               data-target="#collapse8" aria-expanded="false"
                                               aria-controls="collapse8">
                                                <h5>Mes données sont-elles sécurisées ?</h5>
                                            </a>
                                        </div>
                                        <div id="collapse8" class="collapse" aria-labelledby="heading8"
                                             data-parent="#faq_accordion1">
                                            <div class="card-body">
                                                <p class="text-size-16 text-left mb-0">
                                                    Oui. Vos données sont isolées, chiffrées et utilisées uniquement pour alimenter votre propre instance ELChat.
                                                    Elles ne sont jamais partagées avec d’autres clients.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="accordion-card">
                                        <div class="card-header" id="heading9">
                                            <a href="#" class="btn btn-link collapsed" data-toggle="collapse"
                                               data-target="#collapse9" aria-expanded="false"
                                               aria-controls="collapse9">
                                                <h5>ELChat est-il difficile à configurer ?</h5>
                                            </a>
                                        </div>
                                        <div id="collapse9" class="collapse" aria-labelledby="heading9"
                                             data-parent="#faq_accordion1">
                                            <div class="card-body">
                                                <p class="text-size-16 text-left mb-0">
                                                    Non. La configuration est simple : connectez vos sources, choisissez vos canaux, et ELChat commence à répondre automatiquement en quelques minutes.
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
                                  data-wow-delay="0.4s">Performance & Impact</span>
                            <h2 class="wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.5s">
                                Conçu pour les équipes,<br>
                                pensé pour la scalabilité
                            </h2>
                            <p class="wow fadeInLeft p-0" data-wow-duration="2s" data-wow-delay="0.6s">
                                ELChat est utilisé pour gérer des volumes élevés d’interactions sur les réseaux sociaux,
                                les sites web et les canaux de support client. Grâce à son moteur de connaissance et
                                son système d’automatisation, les entreprises peuvent répondre instantanément,
                                qualifier leurs prospects et améliorer l’expérience client à grande échelle.
                            </p>

                            <a href="about.html" class="text-decoration-none primary_btn d-inline-block wow
                                fadeInDown" data-wow-duration="2s" data-wow-delay="0.6s">Commencer</a>
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
                                    <span class="d-inline-block black-text counter">95 </span><sup
                                        class="d-inline-block black-text">%</sup>
                                    <span class="span-text d-block">Réduction du temps de réponse</span>
                                    <!-- statistics box -->
                                </div>
                                <!-- col -->
                            </div>
                            <div class="col-lg-6 col-md-6 d-flex">
                                <div class="statistics-box w-100">
                                    <figure><img src="{{ asset('assets/images/statistics-icon2.png')}}" alt="icon" class="img-fluid">
                                    </figure>
                                    <span class="d-inline-block black-text">24/7 </span>
                                    <!-- <span class="d-inline-block alphabet black-text">k</span> -->
                                    <span class="span-text d-block">Disponibilité continue</span>
                                    <!-- statistics box -->
                                </div>
                                <!-- col -->
                            </div>
                            <div class="col-lg-6 col-md-6 d-flex">
                                <div class="statistics-box w-100">
                                    <figure><img src="{{ asset('assets/images/statistics-icon3.png')}}" alt="icon" class="img-fluid">
                                    </figure>
                                    <sup class="d-inline-block black-text">+</sup><span
                                        class="d-inline-block black-text counter">40 </span><sup
                                        class="d-inline-block black-text">%</sup>
                                    <span class="span-text d-block">Augmentation de l’engagement</span>
                                    <!-- statistics box -->
                                </div>
                                <!-- col -->
                            </div>
                            <div class="col-lg-6 col-md-6 d-flex">
                                <div class="statistics-box w-100">
                                    <figure><img src="{{ asset('assets/images/statistics-icon4.png')}}" alt="icon" class="img-fluid">
                                    </figure>
                                    <span class="d-inline-block black-text counter">10000 </span><sup
                                        class="d-inline-block black-text">+</sup>
                                    <span class="span-text d-block">Conversations gérées quotidiennement</span>
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
