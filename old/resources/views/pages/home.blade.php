@extends('pages.layouts.blank')

@section('seo')
    <!-- Primary Meta Tags -->
    <title>ELChat | Plateforme d'IA Conversationnelle pour Entreprises</title>

    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <meta name="title" content="ELChat | Plateforme d'IA Conversationnelle pour Entreprises">

    <meta name="description"
          content="Transformez les connaissances de votre entreprise en conversations intelligentes. ELChat connecte votre site web, vos documents, vos FAQ, vos produits et vos réseaux sociaux pour automatiser l'engagement client, le support et la génération de prospects grâce à l'intelligence artificielle.">

    <meta name="keywords"
          content="IA conversationnelle, plateforme IA, intelligence artificielle entreprise, assistant IA, chatbot intelligent, automatisation des conversations, support client IA, génération de leads IA, engagement client, IA Instagram, IA YouTube, automatisation réseaux sociaux, base de connaissances IA, FAQ intelligente, IA entreprise, ELChat">

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
          content="ELChat | Une IA qui comprend réellement votre entreprise">

    <meta property="og:description"
          content="Connectez votre site web, vos documents, vos FAQ et vos produits. ELChat transforme les connaissances de votre entreprise en conversations intelligentes et automatise vos interactions sur vos canaux d'engagement.">

    <meta property="og:url" content="https://elchat.io/accueil">

    <meta property="og:image"
          content="https://elchat.io/assets/images/sub-banner-img.png">

    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">

    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">

    <meta name="twitter:title"
          content="ELChat | Plateforme d'IA Conversationnelle pour Entreprises">

    <meta name="twitter:description"
          content="Une plateforme d'IA conversationnelle alimentée par les connaissances de votre entreprise et connectée à vos canaux d'engagement.">

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
                            L'<span class="d-inline-block font-weight-bold color-blue">IA</span> conversationnelle <br> alimentée par les <span class="d-inline-block font-weight-bold color-blue">connaissances</span> de votre entreprise
                        </h1>
                        <p>
                            Connectez votre site web, vos documents, vos FAQ et vos catalogues produits.
                            ELChat transforme ces informations en conversations intelligentes et automatise l'engagement avec vos prospects et clients sur l'ensemble de vos canaux numériques.
                        </p>
                        <a href="{{ route('about.page') }}" class="text-decoration-none primary_btn d-inline-block">Essai gratuit</a>
                        <a href="{{ route('contact.page') }}" class="text-decoration-none secondary_btn d-inline-block">Nous contacter</a>
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
                      data-wow-delay="0.2s">Nos clients</span>
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
                                Pour automatiser, centraliser et optimiser les conversations avec leurs clients <br>
                                sur l'ensemble de leurs canaux de communication:</p>
                            <div class="logos-con d-flex align-items-center justify-content-between wow fadeIn"
                                 data-wow-duration="2s" data-wow-delay="0.2s">
                                <figure><img src="https://drmaxisliterie.re/assets/img/logo_sticky.svg" alt="shopify" class="img-fluid wow fadeInRight"
                                             data-wow-duration="2s" data-wow-delay="0.6s"></figure>
                                <figure><img src="https://inayya.re/wp-content/uploads/2026/02/logo-inayya-web.png" alt="slack" class="img-fluid wow fadeInRight"
                                             data-wow-duration="2s" data-wow-delay="1.0s"></figure>
                                <figure><img src="https://webenvue-mutualise-02.s3.eu-west-3.amazonaws.com/cm2s/2026/02/logo-cm2s-noir.svg" alt="zendesk" class="img-fluid wow fadeInRight"
                                             data-wow-duration="2s" data-wow-delay="1.4s"></figure>
                                {{--<figure><img src="assets/images/client-logo4.png" alt="discord" class="img-fluid wow fadeInRight"
                                             data-wow-duration="2s" data-wow-delay="1.8s"></figure>
                                <figure><img src="assets/images/client-logo5.png" alt="telegram" class="img-fluid wow fadeInRight"
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
                      data-wow-delay="0.2s">Fonctionnalités clés</span>
                <h2 class="wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.4s">
                    Une IA qui comprend votre entreprise,<br> apprend de vos données & engage vos clients
                </h2>
                <!-- heading title con -->
            </div>
            <div class="row all_row wow fadeIn" data-wow-duration="2s" data-wow-delay="0.4s">
                <div class="col-lg-4 col-md-6 all_column wow fadeInLeft" data-wow-duration="2s" data-wow-delay="0.5s">
                    <div class="feature-box position-relative all_boxes">
                        <h4>IA conversationnelle contextuelle</h4>
                        <p class="mb-0">
                            ELChat ne se limite pas à répondre.
                            Il analyse vos conversations et génère des réponses basées sur vos connaissances réelles :
                            site web, documents, FAQ et produits. Chaque interaction est cohérente avec votre activité.
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
                        <h4>Connexion multi-canaux</h4>
                        <p class="mb-0">
                            Centralisez tous vos échanges sur une seule plateforme.
                            ELChat s’intègre à vos réseaux sociaux, votre site web et vos canaux de messagerie pour engager vos prospects là où ils se trouvent.
                            Une seule IA, tous vos points de contact.
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
                        <h4>Analyse et optimisation en temps réel</h4>
                        <p class="mb-0">
                            Suivez les performances de vos conversations, comprenez le comportement de vos utilisateurs et optimisez vos réponses automatiquement.
                            ELChat apprend continuellement de chaque interaction.
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
                                Une IA qui apprend, comprend et agit
                            </h2>
                            <!-- heading title con -->
                        </div>
                        <ul class="list-unstyled p-0">
                            <li class="position-relative d-flex align-items-center">
                                <span class="d-block color-blue">01</span>
                                <div class="work-content-inner-con">
                                    <h5>Connectez vos sources</h5>
                                    <p class="mb-0">
                                        Connectez votre site web, vos documents, vos FAQ et vos produits.
                                        ELChat analyse automatiquement vos données pour construire une base de connaissances complète et structurée.
                                    </p>
                                    <!-- work content inner con -->
                                </div>
                            </li>
                            <li class="position-relative d-flex align-items-center">
                                <span class="d-block color-blue">02</span>
                                <div class="work-content-inner-con">
                                    <h5>Entraînez votre IA avec votre contenu</h5>
                                    <p class="mb-0">
                                        ELChat transforme vos informations en intelligence conversationnelle.
                                        Chaque réponse est générée à partir de vos propres données : services, produits, politiques et contenus métier.
                                    </p>
                                    <!-- work content inner con -->
                                </div>
                            </li>
                            <li class="position-relative d-flex align-items-center">
                                <span class="d-block color-blue">03</span>
                                <div class="work-content-inner-con">
                                    <h5>Déployez et automatisez vos conversations</h5>
                                    <p class="mb-0">
                                        Activez ELChat sur vos réseaux sociaux, votre site web et vos canaux de communication.
                                        L’IA répond automatiquement à vos prospects et clients avec un niveau de précision aligné sur votre entreprise.
                                    </p>
                                    <!-- work content inner con -->
                                </div>
                            </li>
                        </ul>
                        <a href="{{ route('contact.page') }}" class="text-decoration-none primary_btn d-inline-block">
                            Essayez maintenant
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

    <!-- WHY CHOOSE US SECTION -->
    <section class="float-left w-100 position-relative why-choose-us-con padding-top padding-bottom main-box">
        <div class="container wow fadeInUp" data-wow-duration="2s" data-wow-delay="0.2s">
            <div class="heading-title-con text-center">
                <span class="special-text color-blue d-block wow fadeInLeft" data-wow-duration="2s"
                      data-wow-delay="0.2s">Pourquoi ELChat</span>
                <h2 class="wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.4s">
                    Une plateforme d’IA conçue pour comprendre<br> et représenter votre entreprise
                </h2>
                <!-- heading title con -->
            </div>
            <div class="choose-outer-con wow fadeInDown" data-wow-duration="2s" data-wow-delay="0.5s">
                <div class="choose-box">
                    <figure><img src="{{ asset('assets/images/choose-icon1.png')}}" alt="icon" class="img-fluid"></figure>
                    <h6>IA alimentée par vos connaissances</h6>
                    <p class="mb-0">
                        ELChat ne répond pas de manière générique.
                        Il s’appuie sur votre site web, vos documents, vos FAQ et vos produits pour générer des réponses précises et cohérentes avec votre activité.
                    </p>
                    <!-- choose box -->
                </div>
                <div class="choose-box">
                    <figure><img src="{{ asset('assets/images/choose-icon2.png')}}" alt="icon" class="img-fluid"></figure>
                    <h6>Engagement multi-canaux automatisé</h6>
                    <p class="mb-0">
                        Interagissez automatiquement avec vos clients sur votre site web, Instagram, YouTube, Facebook et autres canaux.
                        ELChat centralise et automatise vos conversations là où vos utilisateurs se trouvent.
                    </p>
                    <!-- choose box -->
                </div>
                <div class="choose-box">
                    <figure><img src="{{ asset('assets/images/choose-icon3.png')}}" alt="icon" class="img-fluid"></figure>
                    <h6>Réponses contextuelles et intelligentes</h6>
                    <p class="mb-0">
                        Chaque réponse est adaptée au contexte réel de l’utilisateur et enrichie par la connaissance de votre entreprise,
                        pour une expérience plus naturelle et pertinente.
                    </p>
                    <!-- choose box -->
                </div>
                <div class="choose-box">
                    <figure><img src="{{ asset('assets/images/choose-icon4.png')}}" alt="icon" class="img-fluid"></figure>
                    <h6>Conçu pour les entreprises en croissance</h6>
                    <p class="mb-0">
                        ELChat évolue avec votre activité.
                        Plus vous ajoutez de contenu, plus l’IA devient précise, pertinente et performante dans ses interactions.
                    </p>
                    <!-- choose box -->
                </div>
                <div class="choose-box">
                    <figure><img src="{{ asset('assets/images/choose-icon5.png')}}" alt="icon" class="img-fluid"></figure>
                    <h6>Une alternative aux chatbots classiques</h6>
                    <p class="mb-0">
                        Contrairement aux chatbots traditionnels, ELChat comprend réellement votre entreprise et ne se limite pas à des réponses préprogrammées ou génériques.
                    </p>
                    <!-- choose box -->
                </div>
                <!-- choose outer con -->
            </div>
            <div class="float-left w-100 m-auto text-center wow fadeInUp" data-wow-duration="2s" data-wow-delay="0.4s">
                <a href="{{ route('about.page')}}" class="text-decoration-none primary_btn d-inline-block">Essai Gratuit</a>
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
                    Une tarification simple, flexible et alignée<br> avec votre usage réel de l’IA
                </h2>
                <p> Payez selon votre utilisation réelle : volume de messages, tokens IA, canaux connectés et niveau d’automatisation. ELChat s’adapte à la taille et à la croissance de votre entreprise. </p>
                <!-- heading title con -->
            </div>
            <div class="row all_row wow fadeInDown" data-wow-duration="2s" data-wow-delay="0.5s">
                <div class="col-lg-4 col-md-6 all_column">
                    <div class="pricing-box w-100 all_boxes">
                        <div class="plan-content">
                            <h3 class="">Starter</h3>
                            <p>
                                Pour les petites entreprises qui souhaitent lancer leur premier assistant IA.
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
                                    1 site web
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    1 réseau social connecté
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Assistant IA basé sur votre site et vos documents
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Import de documents
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Indexation automatique du site web
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Réponses avec sources
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Historique des conversations
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Widget personnalisable
                                </li>

                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Jusqu’à 50 messages / mois
                                </li>
                            </ul>
                            <a href="{{ route('contact.page') }}" class="text-decoration-none primary_btn">Commencer</a>
                        </div>
                    </div>
                </div>
                <!-- 🟡 BASIC (NOUVEAU BLOC) -->
                <div class="col-lg-4 col-md-6 all_column">
                    <div class="el-default-pricing pricing-box w-100 all_boxes">
                        <div class="plan-content">
                            <h3 class="">Business</h3>
                            <p>
                                Pour les entreprises qui souhaitent automatiser leur relation client sur plusieurs canaux.
                            </p>
                            <div class="generic-price d-inline-block">
                                <span class="d-block starting-at">À partir de :</span>
                                <sup class="d-inline-block font-weight-normal">€</sup>
                                <span class="d-inline-block price-text font-weight-600">79</span>
                                <span class="d-inline-block per-month mb-0 position-relative font-weight-normal">
                                    /mois
                                </span>
                            </div>
                        </div>

                        <div class="plan-listing">
                            <ul class="list-unstyled p-0 ">
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Tout Starter
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    2 réseaux sociaux connectés
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    boîte e-mail professionnelle connectée
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Catalogue produits
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Suggestions automatiques d'amélioration de la base de connaissances
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Jusqu’à 150 messages / mois
                                </li>
                            </ul>
                            <a href="{{ route('contact.page') }}" class="text-decoration-none primary_btn">Commencer</a>
                        </div>
                    </div>
                </div>
                <!-- 🔵 PRO -->
                <div class="col-lg-4 col-md-6 all_column">
                    <div class="pricing-box w-100 all_boxes">
                        <div class="plan-content">
                            <h3 class="">Pro</h3>
                            <p>
                                Pour les entreprises en croissance qui automatisent plusieurs sites et canaux à grande échelle.
                            </p>
                            <div class="generic-price d-inline-block">
                                <span class="d-block starting-at">
                                    À partir de :
                                </span>
                                <sup class="d-inline-block font-weight-normal">€</sup>
                                <span class="d-inline-block price-text font-weight-600">199</span>
                                <span class="d-inline-block per-month mb-0 position-relative font-weight-normal">
                                    /mois
                                </span>
                            </div>
                        </div>

                        <div class="plan-listing">
                            <ul class="list-unstyled p-0 ">
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Tout Business
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Jusqu'à 3 sites
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Jusqu'à 3 réseaux sociaux par site
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    1 boîte e-mail professionnelle connectée par site
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Gestion multi-sites
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Jusqu’à 300 messages / mois (GLOBAL)
                                </li>
                            </ul>
                            <a href="{{ route('contact.page') }}" class="text-decoration-none primary_btn">Commencer</a>
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
                              data-wow-delay="0.2s">Faq's</span>
                        <h2 class=" wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.4s">
                            Tout ce que vous devez savoir sur ELChat<br> et l’IA conversationnelle
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
                                                    Qu’est-ce qu’ELChat exactement ?
                                                </h6>
                                            </a>
                                        </div>
                                        <div id="collapseOne" class="collapse" aria-labelledby="headingOne"
                                             data-parent="#faq_accordion1">
                                            <div class="card-body">
                                                <p class="text-size-16 text-left mb-0">
                                                    ELChat est une plateforme d’IA conversationnelle qui transforme les connaissances de votre entreprise (site web, documents, FAQ, produits) en conversations automatisées sur vos réseaux sociaux et digitaux.
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
                                                    Comment ELChat génère ses réponses ?
                                                </h6>
                                            </a>
                                        </div>
                                        <div id="collapseTwo" class="show collapse" aria-labelledby="headingTwo"
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
                                                <h6>Quelle est la différence entre messages, tokens et chunks ?</h6>
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
                                                <h6>Quels canaux sont supportés par ELChat ?</h6>
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
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
