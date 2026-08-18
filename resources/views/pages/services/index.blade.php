@extends('pages.layouts.blank')

@section('seo')
    <!-- Primary Meta Tags -->
    <title>Capacités ELChat | Engagement proactif, RAG, workflows et agents IA</title>

    <meta name="title" content="Capacités ELChat | Engagement proactif, RAG, workflows et agents IA">

    <meta name="description"
          content="Découvrez les capacités d'ELChat : engagement proactif, base de connaissances RAG, omnicanal, connecteurs métier, workflows, intelligence événementielle et agents IA.">

    <meta name="keywords"
          content="RAG entreprise, engagement proactif, relance contextuelle, workflows IA, connecteurs MCP, agents IA spécialisés, automatisation métier, intelligence événementielle, omnicanal, AI Sales Hunter, ELChat">

    <meta name="author" content="ELChat">
    <meta name="robots" content="index, follow">

    <link rel="canonical" href="https://elchat.io/services">

    <!-- Open Graph -->
    <meta property="og:type" content="website">
    <meta property="og:locale" content="fr_FR">
    <meta property="og:site_name" content="ELChat">

    <meta property="og:title"
          content="ELChat | Les capacités d'une IA reliée à vos opérations">

    <meta property="og:description"
          content="Connectez connaissances, canaux et outils métier pour analyser les événements, orchestrer les décisions et exécuter les actions utiles.">

    <meta property="og:url"
          content="https://elchat.io/services">

    <meta property="og:image"
          content="https://elchat.io/assets/images/sub-banner-img.png">

    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">

    <meta name="twitter:title"
          content="Capacités ELChat | Plateforme d'IA opérationnelle">

    <meta name="twitter:description"
          content="RAG, engagement proactif, omnicanal, workflows, connecteurs et agents spécialisés réunis dans une plateforme d'IA opérationnelle.">

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
                        <h1>Capacités de la plateforme</h1>
                        <p>
                            Donnez à vos équipes une IA qui connaît votre activité, comprend les événements, recommande la prochaine décision et agit dans les outils autorisés.
                        </p>
                        <div class="breadcrumb-con d-inline-block">
                            <ol class="breadcrumb mb-0">
                                <li class="breadcrumb-item"><a href="{{ route('home.page') }}">Accueil</a></li>
                                <li class="breadcrumb-item active" aria-current="page">Capacités</li>
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
                      data-wow-delay="0.2s">Fondations opérationnelles</span>
                <h2 class="wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.4s">
                    Reliez la connaissance, les canaux<br> et les systèmes métier
                </h2>
                <!-- heading title con -->
            </div>
            <div class="row all_row wow fadeIn" data-wow-duration="2s" data-wow-delay="0.4s">
                <div class="col-lg-4 col-md-6 all_column wow fadeInLeft" data-wow-duration="2s" data-wow-delay="0.5s">
                    <div class="feature-box position-relative all_boxes">
                        <h4>Knowledge Intelligence & RAG</h4>
                        <p class="mb-0">
                            Importez ou indexez vos sources, puis retrouvez le contexte utile grâce à la recherche hybride et aux embeddings.
                            Les réponses peuvent s’appuyer sur vos propres contenus et leurs sources.
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
                        <h4>Community & omnicanal</h4>
                        <p class="mb-0">
                            Centralisez le site, les réseaux sociaux, les messageries et l’e-mail autour d’une même connaissance.
                            Les équipes gardent une vision cohérente des interactions, quel que soit le canal.
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
                        <h4>Business Automation & connecteurs</h4>
                        <p class="mb-0">
                            Connectez CRM, e-commerce, agenda, stockage, marketing et productivité.
                            Les workflows coordonnent les étapes, tandis que les permissions encadrent les actions exécutées.
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

    <!-- PROACTIVE ENGAGEMENT SECTION -->
    <section class="float-left w-100 position-relative why-choose-us-con padding-top padding-bottom main-box">
        <div class="container wow fadeInUp" data-wow-duration="2s" data-wow-delay="0.2s">
            <div class="heading-title-con text-center">
                <span class="special-text color-blue d-block wow fadeInLeft" data-wow-duration="2s" data-wow-delay="0.2s">Nouvelle capacité</span>
                <h2 class="wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.4s">Engagement Proactif</h2>
                <p class="mx-auto" style="max-width: 760px;">
                    ELChat peut reprendre une conversation avec un visiteur lorsqu’un événement métier indique qu’une aide est utile : demande de devis inachevée, forte intention, panier abandonné ou rendez-vous non finalisé.
                </p>
            </div>
            <div class="choose-outer-con wow fadeInDown" data-wow-duration="2s" data-wow-delay="0.5s">
                <div class="choose-box">
                    <h6>Observer le bon signal</h6>
                    <p class="mb-0">Les événements conversationnels, commerciaux et connectés déclenchent une opportunité vérifiable.</p>
                </div>
                <div class="choose-box">
                    <h6>Décider avec le contexte</h6>
                    <p class="mb-0">L’agent utilise mémoire, résumé, historique, profil visiteur et RAG sans inventer d’information.</p>
                </div>
                <div class="choose-box">
                    <h6>Agir sous contrôle</h6>
                    <p class="mb-0">Permissions, quotas, cooldowns, horaires et validations humaines encadrent chaque canal.</p>
                </div>
                <div class="choose-box">
                    <h6>Mesurer le résultat</h6>
                    <p class="mb-0">Réponses, leads, rendez-vous, opportunités et ventes sont attribués selon les données réellement observées.</p>
                </div>
            </div>
            <div class="float-left w-100 m-auto text-center wow fadeInUp" data-wow-duration="2s" data-wow-delay="0.4s">
                <a href="{{ route('contact.page') }}" class="text-decoration-none primary_btn d-inline-block">Découvrir cette capacité</a>
            </div>
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
                                Des événements aux actions utiles
                            </h2>
                            <!-- heading title con -->
                        </div>
                        <ul class="list-unstyled p-0">
                            <li class="position-relative d-flex align-items-center">
                                <span class="d-block color-blue">01</span>
                                <div class="work-content-inner-con">
                                    <h5>Détectez les signaux métier</h5>
                                    <p class="mb-0">
                                        Intentions, conversions, demandes support, opportunités, rendez-vous et exécutions de workflows deviennent des événements exploitables.
                                    </p>
                                    <!-- work content inner con -->
                                </div>
                            </li>
                            <li class="position-relative d-flex align-items-center">
                                <span class="d-block color-blue">02</span>
                                <div class="work-content-inner-con">
                                    <h5>Éclairez la décision</h5>
                                    <p class="mb-0">
                                        Les analyses croisent connaissances et données connectées pour produire diagnostics, synthèses, rapports et plans d’action priorisés.
                                    </p>
                                    <!-- work content inner con -->
                                </div>
                            </li>
                            <li class="position-relative d-flex align-items-center">
                                <span class="d-block color-blue">03</span>
                                <div class="work-content-inner-con">
                                    <h5>Exécutez et tracez</h5>
                                    <p class="mb-0">
                                        Un workflow ou un agent peut préparer puis réaliser l’action permise. Les confirmations et journaux d’audit maintiennent l’humain dans la boucle.
                                    </p>
                                    <!-- work content inner con -->
                                </div>
                            </li>
                        </ul>
                        <a href="{{ route('contact.page') }}" class="text-decoration-none primary_btn d-inline-block">
                            Cartographier vos cas d’usage
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
                    Des capacités spécialisées,<br> un même environnement de travail
                </h2>
                <!-- heading title con -->
            </div>
            <div class="choose-outer-con wow fadeInDown" data-wow-duration="2s" data-wow-delay="0.5s">
                <div class="choose-box">
                    <figure><img src="{{ asset('assets/images/choose-icon1.png')}}" alt="icon" class="img-fluid"></figure>
                    <h6>Knowledge Intelligence</h6>
                    <p class="mb-0">
                        Structurez la mémoire opérationnelle de l’entreprise, mesurez sa qualité et repérez les questions encore sans réponse fiable.
                    </p>
                    <!-- choose box -->
                </div>
                <div class="choose-box">
                    <figure><img src="{{ asset('assets/images/choose-icon2.png')}}" alt="icon" class="img-fluid"></figure>
                    <h6>Event & Business Intelligence</h6>
                    <p class="mb-0">
                        Suivez les événements conversationnels, commerciaux et opérationnels pour comprendre les parcours et relier les actions aux résultats.
                    </p>
                    <!-- choose box -->
                </div>
                <div class="choose-box">
                    <figure><img src="{{ asset('assets/images/choose-icon3.png')}}" alt="icon" class="img-fluid"></figure>
                    <h6>Executive Intelligence</h6>
                    <p class="mb-0">
                        Générez briefings, diagnostics transverses et plans d’action à partir des sources et outils connectés à ELChat.
                    </p>
                    <!-- choose box -->
                </div>
                <div class="choose-box">
                    <figure><img src="{{ asset('assets/images/choose-icon4.png')}}" alt="icon" class="img-fluid"></figure>
                    <h6>Agents IA spécialisés</h6>
                    <p class="mb-0">
                        Installez des agents orientés vers un rôle précis, associez-leur des compétences et choisissez leur niveau d’autonomie.
                    </p>
                    <!-- choose box -->
                </div>
                <div class="choose-box">
                    <figure><img src="{{ asset('assets/images/choose-icon5.png')}}" alt="icon" class="img-fluid"></figure>
                    <h6>AI Sales Hunter</h6>
                    <p class="mb-0">
                        Identifiez et qualifiez des prospects, préparez les prises de contact et pilotez les campagnes selon des limites et validations configurables.
                    </p>
                    <!-- choose box -->
                </div>
                <!-- choose outer con -->
            </div>
            <div class="float-left w-100 m-auto text-center wow fadeInUp" data-wow-duration="2s" data-wow-delay="0.4s">
                <a href="{{ route('about.page')}}" class="text-decoration-none primary_btn d-inline-block">Voir la vision produit</a>
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
                              data-wow-delay="0.2s">Questions fréquentes</span>
                        <h2 class=" wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.4s">
                            Ce qu’il faut savoir sur<br> l’exécution par l’IA
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
                                                    Quels outils métier peut-on connecter ?
                                                </h6>
                                            </a>
                                        </div>
                                        <div id="collapseOne" class="collapse" aria-labelledby="headingOne"
                                             data-parent="#faq_accordion1">
                                            <div class="card-body">
                                                <p class="text-size-16 text-left mb-0">
                                                    Le catalogue comprend notamment HubSpot, Odoo, Shopify, WooCommerce, Google Calendar, Google Drive, OneDrive, Slack, Teams, Notion, Asana, ClickUp, Trello et plusieurs outils marketing. La disponibilité dépend du connecteur et de sa configuration.
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
                                                    Quelle différence entre un workflow et un agent ?
                                                </h6>
                                            </a>
                                        </div>
                                        <div id="collapseTwo" class="show collapse" aria-labelledby="headingTwo"
                                             data-parent="#faq_accordion1">
                                            <div class="card-body">
                                                <p class="text-size-16 text-left mb-0">
                                                    Un workflow enchaîne des étapes définies pour un processus récurrent. Un agent poursuit un objectif avec les compétences, connecteurs et workflows qui lui sont attribués, dans les limites de son autonomie.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="accordion-card">
                                        <div class="card-header" id="headingThree">
                                            <a href="#" class="btn btn-link collapsed" data-toggle="collapse"
                                               data-target="#collapseThree" aria-expanded="false"
                                               aria-controls="collapseThree">
                                                <h6>Comment les actions sensibles sont-elles contrôlées ?</h6>
                                            </a>
                                        </div>
                                        <div id="collapseThree" class="collapse" aria-labelledby="headingThree"
                                             data-parent="#faq_accordion1">
                                            <div class="card-body">
                                                <p class="text-size-16 text-left mb-0">
                                                    Les permissions déterminent si une action est autorisée, bloquée ou soumise à confirmation. Une file d’actions en attente et un journal d’audit permettent aux équipes de garder le contrôle.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="accordion-card">
                                        <div class="card-header" id="headingFour">
                                            <a href="#" class="btn btn-link collapsed" data-toggle="collapse"
                                               data-target="#collapseFour" aria-expanded="false"
                                               aria-controls="collapseFour">
                                                <h6>Que fait concrètement l’AI Sales Hunter ?</h6>
                                            </a>
                                        </div>
                                        <div id="collapseFour" class="collapse" aria-labelledby="headingFour"
                                             data-parent="#faq_accordion1">
                                            <div class="card-body">
                                                <p class="text-size-16 text-left mb-0">
                                                    Il aide à découvrir et qualifier des prospects, analyser leur site, rédiger une approche et suivre leur progression. L’envoi peut rester en suggestion, exiger une validation humaine ou être automatisé selon la configuration choisie.
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
