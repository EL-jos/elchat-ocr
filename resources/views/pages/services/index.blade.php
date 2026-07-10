@extends('pages.layouts.blank')

@section('seo')
    <!-- Primary Meta Tags -->
    <title>Services ELChat | IA Conversationnelle pour l'Engagement Client</title>

    <meta name="title" content="Services ELChat | IA Conversationnelle pour l'Engagement Client">

    <meta name="description"
          content="Découvrez les services d'ELChat : automatisation des conversations, engagement client, support intelligent, génération de prospects, gestion des connaissances et intégration aux réseaux sociaux grâce à l'intelligence artificielle.">

    <meta name="keywords"
          content="services IA conversationnelle, engagement client IA, support client automatisé, génération de prospects IA, automatisation réseaux sociaux, intelligence artificielle entreprise, assistant IA, plateforme conversationnelle, knowledge base IA, ELChat">

    <meta name="author" content="ELChat">
    <meta name="robots" content="index, follow">

    <link rel="canonical" href="https://elchat.io/services">

    <!-- Open Graph -->
    <meta property="og:type" content="website">
    <meta property="og:locale" content="fr_FR">
    <meta property="og:site_name" content="ELChat">

    <meta property="og:title"
          content="Services ELChat | Automatisez vos conversations grâce à l'IA">

    <meta property="og:description"
          content="Connectez vos connaissances, vos produits et vos canaux d'engagement. ELChat automatise les conversations tout en restant fidèle à votre activité.">

    <meta property="og:url"
          content="https://elchat.io/services">

    <meta property="og:image"
          content="https://elchat.io/assets/images/sub-banner-img.png">

    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">

    <meta name="twitter:title"
          content="Services ELChat | Plateforme d'IA Conversationnelle">

    <meta name="twitter:description"
          content="Découvrez comment ELChat aide les entreprises à automatiser leurs conversations, améliorer leur support et engager leurs prospects grâce à l'IA.">

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
                        <h1>Services</h1>
                        <p>
                            Transformez les connaissances de votre entreprise en conversations intelligentes grâce à une
                            plateforme d'IA capable d'apprendre à partir de votre site web, de vos documents, de vos produits et de vos canaux d'engagement.
                        </p>
                        <div class="breadcrumb-con d-inline-block">
                            <ol class="breadcrumb mb-0">
                                <li class="breadcrumb-item"><a href="{{ route('home.page') }}">Accueil</a></li>
                                <li class="breadcrumb-item active" aria-current="page">Services</li>
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
    <section class="float-left w-100 position-relative why-choose-us-con padding-top main-box">
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
