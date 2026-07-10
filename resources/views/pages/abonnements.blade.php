@extends('pages.layouts.blank')

@section('seo')
    <!-- Primary Meta Tags -->
    <title>Tarifs ELChat | Plans et Abonnements de l'IA Conversationnelle</title>

    <meta name="title" content="Tarifs ELChat | Plans et Abonnements de l'IA Conversationnelle">

    <meta name="description"
          content="Découvrez les tarifs d'ELChat. Choisissez le plan adapté à votre activité et transformez les connaissances de votre entreprise en conversations intelligentes grâce à une plateforme d'IA conversationnelle connectée à vos données et vos canaux d'engagement.">

    <meta name="keywords"
          content="tarifs ELChat, prix IA conversationnelle, abonnement IA entreprise, plateforme IA entreprise, coût chatbot entreprise, automatisation support client, engagement client IA, assistant IA professionnel">

    <meta name="author" content="ELChat">
    <meta name="robots" content="index, follow">

    <link rel="canonical" href="https://elchat.io/tarifs">

    <!-- Open Graph -->
    <meta property="og:type" content="website">
    <meta property="og:locale" content="fr_FR">
    <meta property="og:site_name" content="ELChat">

    <meta property="og:title"
          content="Tarifs ELChat | Choisissez le plan adapté à votre croissance">

    <meta property="og:description"
          content="Des abonnements flexibles pour automatiser l'engagement client, le support et les conversations grâce à l'intelligence artificielle.">

    <meta property="og:url"
          content="https://elchat.io/tarifs">

    <meta property="og:image"
          content="https://elchat.io/assets/images/sub-banner-img.png">

    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">

    <meta name="twitter:title"
          content="Tarifs ELChat">

    <meta name="twitter:description"
          content="Découvrez les abonnements ELChat pour automatiser les conversations de votre entreprise grâce à l'IA.">

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
                        <h1>Tarifs</h1>
                        <p>
                            Investissez dans une plateforme d’IA conversationnelle qui s’appuie sur les connaissances réelles de votre entreprise.
                            ELChat connecte votre site web, vos documents, vos FAQ,
                            vos produits et vos canaux d’engagement pour automatiser des conversations pertinentes avec vos prospects et clients.
                        </p>
                        <div class="breadcrumb-con d-inline-block">
                            <ol class="breadcrumb mb-0">
                                <li class="breadcrumb-item"><a href="{{ route('home.page')}}">Accueil</a></li>
                                <li class="breadcrumb-item active" aria-current="page">Tarifs</li>
                            </ol>
                        </div>
                        <!-- sub banner content con -->
                    </div>

                    <!-- col -->
                </div>
                <div class="col-lg-5 col-md-5">
                    <div class="sub-banner-img-con">
                        <figure>
                            <img src="assets/images/sub-banner-img.png" alt="robot">
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

    <!-- PRICING PLAN SECTION -->
    <section
        class="float-left w-100 position-relative pricing-plan-con padding-top padding-bottom main-box main-pricing-con">
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
            <div class="row all_row wow fadeInDown" data-wow-duration="2s" data-wow-delay="0.6s">
                <div class="col-lg-4 col-md-6 all_column">
                    <div class="pricing-box w-100 all_boxes">
                        <div class="plan-content">
                            <h3 class="">Starter</h3>
                            <p>
                                Pour les petites entreprises qui testent l’automatisation de leurs conversations avec l’IA.
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
                                    1 site
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    1 réseau social
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Jusqu’à 50 messages / mois
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Jusqu’à 10 000 chunks de connaissances
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Jusqu’à 1M tokens IA (usage optimisé)
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
                                Pour les indépendants et petites équipes qui veulent une automatisation plus sérieuse.
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
                                    1 site
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    3 réseaux sociaux
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Jusqu’à 150 messages / mois
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Jusqu’à 55 000 chunks de connaissances
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Jusqu’à 3M tokens IA
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
                                    3 sites
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    3 réseaux sociaux par site
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Jusqu’à 300 messages / mois (GLOBAL)
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Jusqu’à 100 000 chunks de connaissances
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Jusqu’à 20M tokens IA
                                </li>
                            </ul>
                            <a href="{{ route('contact.page') }}" class="text-decoration-none primary_btn">Commencer</a>
                        </div>
                    </div>
                </div>
                <!-- 🟠 ENTERPRISE -->
                <div class="col-lg-4 col-md-6 all_column">
                    <div class="pricing-box w-100 all_boxes">
                        <div class="plan-content">
                            <h3 class="">Enterprise</h3>
                            <p>
                                Une offre sur mesure entièrement personnalisable,
                                pensée pour les entreprises et agences qui souhaitent adapter ELChat à leurs besoins spécifiques,
                                leurs outils et leurs objectifs.
                            </p>
                            <div class="generic-price d-inline-block">
                                <span class="d-block starting-at">
                                    À partir de :
                                </span>
                                <sup class="d-inline-block font-weight-normal">€</sup>
                                <span class="d-inline-block price-text font-weight-600">499</span>
                                <span class="d-inline-block per-month mb-0 position-relative font-weight-normal">
                                    /mois
                                </span>
                            </div>
                        </div>

                        <div class="plan-listing">
                            <ul class="list-unstyled p-0 ">
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    À partir de 3 sites
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    5 réseaux sociaux par site
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Jusqu’à 900 messages / mois (GLOBAL)
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Jusqu’à 200 000 chunks de connaissances
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    Jusqu’à 100M tokens IA
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    SLA premium
                                </li>
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    White-label option
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
