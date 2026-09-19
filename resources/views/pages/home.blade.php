@extends('pages.layouts.blank')

@section('seo')
    @include('pages.partials.seo', ['page' => 'home'])
    {{--
    <!-- Primary Meta Tags -->
    <title>IA conversationnelle pour entreprises | Plateforme ELChat</title>

    <meta name="title" content="IA conversationnelle pour entreprises | Plateforme ELChat">

    <meta name="description"
          content="ELChat est une plateforme d’IA conversationnelle pour entreprises. Reliez vos connaissances et outils pour automatiser les interactions et mesurer les résultats.">

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
          content="IA conversationnelle pour entreprises | Plateforme ELChat">

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
    --}}
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
                        <h1>{{ __('site.home.hero_title') }}</h1>
                        <p>
                            {{ __('site.home.hero_description') }}
                        </p>
                        <a href="{{ \App\Support\SiteLocale::urlForPage('about') }}" class="text-decoration-none primary_btn d-inline-block">{{ __('site.home.discover') }}</a>
                        <a href="{{ \App\Support\SiteLocale::urlForPage('contact') }}" class="text-decoration-none secondary_btn d-inline-block">{{ __('site.home.demo') }}</a>
                        <!-- banner content con -->
                    </div>
                    <!-- col -->
                </div>
                <div class="col-lg-5 col-md-5">
                    <div class="banner-img-con position-relative">
                        <figure><img src="{{ asset('assets/images/banner-robot.png')}}" alt="Assistant IA conversationnel ELChat" class="animated-robot"></figure>
                        <div class="coment-box1 d-flex align-items-center popup-bubble popup-delay-1">
                            <img src="{{ asset('assets/images/coment-box-icon1.png')}}" alt="" class="img-fluid">
                            <p class="typing mb-0" id="text1"></p>
                            <!-- coment box1 -->
                        </div>
                        <div class="coment-box2 d-flex align-items-center popup-bubble popup-delay-2">
                            <img src="{{ asset('assets/images/coment-box-icon2.png')}}" alt="" class="img-fluid">
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
                        <img src="{{ asset('assets/images/banner-dropdownimage.png')}}" class="img-fluid" alt="Voir les solutions IA d’ELChat">
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
                      data-wow-delay="0.2s">{{ __('site.home.designed') }}</span>
                <h2 class="wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.4s">
                    {{ __('site.home.trusted') }}
                </h2>
                <!-- heading title con -->
            </div>
            <div class="row all_row wow fadeIn" data-wow-duration="2s" data-wow-delay="0.4s">
                <div class="float-left w-100 client-logo-con position-relative main-box" id="client">
                    <div class="container wow fadeInUp" data-wow-duration="2s" data-wow-delay="0.2s">
                        <div class="client-logo-inner d-flex align-items-center justify-content-between">
                            <p class="wow fadeInLeft" data-wow-duration="2s" data-wow-delay="0.2s">
                                {{ __('site.home.trusted_text') }}
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
                                <figure><img src="https://ledsrun.re/wp-content/uploads/2025/10/Logo-web-head.png" alt="LED's RUN" class="img-fluid wow fadeInRight"
                                             data-wow-duration="2s" data-wow-delay="2.2s"></figure>
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
                      data-wow-delay="0.2s">{{ __('site.home.pillars') }}</span>
                <h2 class="wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.4s">
                    {{ __('site.home.pillars_title') }}
                </h2>
                <!-- heading title con -->
            </div>
            <div class="row all_row wow fadeIn" data-wow-duration="2s" data-wow-delay="0.4s">
                <div class="col-lg-4 col-md-6 all_column wow fadeInLeft" data-wow-duration="2s" data-wow-delay="0.5s">
                    <div class="feature-box position-relative all_boxes">
                        <h4>{{ __('site.home.rag_title') }}</h4>
                        <p class="mb-0">
                            {{ __('site.home.rag_text') }}
                        </p>
                        <img src="{{ asset('assets/images/feature-img1-icon1.png')}}" alt="Icône de base de connaissances RAG"
                             class="img-fluid position-absolute feature-icon1  wow fadeInUp" data-wow-duration="2s"
                             data-wow-delay="0.6s">

                        <figure><img src="{{ asset('assets/images/feature-img1.png')}}" alt="Illustration de la base de connaissances ELChat"
                                     class="img-fluid  wow fadeInDown" data-wow-duration="2s" data-wow-delay="0.7s">
                        </figure>
                        <a href="{{ \App\Support\SiteLocale::urlForPage('services') }}" aria-label="{{ __('site.services.detail') }} {{ __('site.services_details.knowledge_rag.name') }}"><img src="{{ asset('assets/images/up-right-arrow.png')}}" alt=""
                                                     class="img-fluid"></a>
                        <!-- feature box -->
                    </div>
                    <!-- col -->
                </div>
                <div class="col-lg-4 col-md-6 all_column wow fadeInUp" data-wow-duration="2s" data-wow-delay="0.5s">
                    <div class="feature-box position-relative all_boxes bg-green">
                        <h4>{{ __('site.home.workflows_title') }}</h4>
                        <p class="mb-0">
                            {{ __('site.home.workflows_text') }}
                        </p>
                        <img src="{{ asset('assets/images/feature-img2-icon1.png')}}" alt="" 
                             class="img-fluid position-absolute feature-icon2  wow fadeInLeft" data-wow-duration="2s"
                             data-wow-delay="0.8s">
                        <img src="{{ asset('assets/images/feature-img2-icon2.png')}}" alt=""
                             class="img-fluid position-absolute feature-icon3  wow fadeInRight" data-wow-duration="2s"
                             data-wow-delay="0.9s">
                        <img src="{{ asset('assets/images/feature-img2-icon3.png')}}" alt=""
                             class="img-fluid position-absolute feature-icon4  wow fadeInLeft" data-wow-duration="2s"
                             data-wow-delay="1.0s">
                        <img src="{{ asset('assets/images/feature-img2-icon4.png')}}" alt=""
                             class="img-fluid position-absolute feature-icon5 wow fadeInRight" data-wow-duration="2s"
                             data-wow-delay="1.1s">
                        <figure><img src="{{ asset('assets/images/feature-img2.png')}}" alt="Illustration des workflows et connecteurs métier ELChat"
                                     class="img-fluid wow fadeInDown" data-wow-duration="2s" data-wow-delay="1.2s">
                        </figure>
                        <a href="{{ \App\Support\SiteLocale::urlForPage('services')}}" aria-label="{{ __('site.services.detail') }} {{ __('site.services_details.workflows_connecteurs.name') }}"><img src="{{ asset('assets/images/up-right-arrow.png')}}" alt=""
                                                     class="img-fluid"></a>
                        <!-- feature box -->
                    </div>
                    <!-- col -->
                </div>
                <div class="col-lg-4 col-md-6 all_column  wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.5s">
                    <div class="feature-box position-relative all_boxes">
                        <h4>{{ __('site.home.business_title') }}</h4>
                        <p class="mb-0">
                            {{ __('site.home.business_text') }}
                        </p>
                        <img src="{{ asset('assets/images/feature-img3-icon1.png')}}" alt="Icône d’analyse des événements métier"
                             class="img-fluid position-absolute feature-icon6 wow fadeInUp" data-wow-duration="2s"
                             data-wow-delay="0.6s">
                        <img src="{{ asset('assets/images/elipse-blue.png')}}" alt=""
                             class="img-fluid position-absolute blue-elipse wow fadeInDown" data-wow-duration="2s"
                             data-wow-delay="0.7s">
                        <figure><img src="{{ asset('assets/images/feature-img3.png')}}" alt="Illustration de l’intelligence business et executive"
                                     class="img-fluid feature-img3 wow fadeIn" data-wow-duration="2s" data-wow-delay="0.8s">
                        </figure>
                        <a href="{{ \App\Support\SiteLocale::urlForPage('services')}}" aria-label="{{ __('site.services.detail') }} {{ __('site.services_details.intelligence_business.name') }}"><img src="{{ asset('assets/images/up-right-arrow.png')}}" alt=""
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
                        <figure><img src="{{ asset('assets/images/work-img.png')}}" alt="Illustration du fonctionnement d’ELChat" class="img-fluid"></figure>
                        <figure><img src="{{ asset('assets/images/robot.png')}}" alt="Assistant IA ELChat"
                                     class="img-fluid position-absolute robot-img animated-robot">
                        </figure>
                    </div>
                    <!-- col -->
                </div>
                <div class="col-lg-5 col-md-12 wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.4s">
                    <div class="work-content-con">
                        <div class="heading-title-con">
                            <span class="special-text color-blue d-block wow fadeInLeft" data-wow-duration="2s"
                      data-wow-delay="0.5s">{{ __('site.home.how_label') }}</span>
                            <h2 class="wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.6s">
                                {{ __('site.home.how_title') }}
                            </h2>
                            <!-- heading title con -->
                        </div>
                        <ul class="list-unstyled p-0">
                            <li class="position-relative d-flex align-items-center">
                                <span class="d-block color-blue">01</span>
                                <div class="work-content-inner-con">
                                    <h5>{{ __('site.home.step1_title') }}</h5>
                                    <p class="mb-0">
                                        {{ __('site.home.step1_text') }}
                                    </p>
                                    <!-- work content inner con -->
                                </div>
                            </li>
                            <li class="position-relative d-flex align-items-center">
                                <span class="d-block color-blue">02</span>
                                <div class="work-content-inner-con">
                                    <h5>{{ __('site.home.step2_title') }}</h5>
                                    <p class="mb-0">
                                        {{ __('site.home.step2_text') }}
                                    </p>
                                    <!-- work content inner con -->
                                </div>
                            </li>
                            <li class="position-relative d-flex align-items-center">
                                <span class="d-block color-blue">03</span>
                                <div class="work-content-inner-con">
                                    <h5>{{ __('site.home.step3_title') }}</h5>
                                    <p class="mb-0">
                                        {{ __('site.home.step3_text') }}
                                    </p>
                                    <!-- work content inner con -->
                                </div>
                            </li>
                        </ul>
                        <a href="{{ \App\Support\SiteLocale::urlForPage('contact') }}" class="text-decoration-none primary_btn d-inline-block">
                            {{ __('site.home.study_case') }}
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
                <span class="special-text color-blue d-block wow fadeInLeft" data-wow-duration="2s" data-wow-delay="0.2s">{{ __('site.home.proactive_label') }}</span>
                <h2 class="wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.4s">
                    {{ __('site.home.proactive_title') }}
                </h2>
                <p class="mx-auto" style="max-width: 760px;">
                    {{ __('site.home.proactive_text') }}
                </p>
            </div>
            <div class="row all_row wow fadeIn" data-wow-duration="2s" data-wow-delay="0.4s">
                <div class="col-lg-4 col-md-6 all_column wow fadeInLeft" data-wow-duration="2s" data-wow-delay="0.5s">
                    <div class="feature-box position-relative all_boxes">
                        <h4>{{ __('site.home.proactive_1_title') }}</h4>
                        <p class="mb-0">{{ __('site.home.proactive_1_text') }}</p>
                    </div>
                </div>
                <div class="col-lg-4 col-md-6 all_column wow fadeInUp" data-wow-duration="2s" data-wow-delay="0.5s">
                    <div class="feature-box position-relative all_boxes bg-green">
                        <h4>{{ __('site.home.proactive_2_title') }}</h4>
                        <p class="mb-0">{{ __('site.home.proactive_2_text') }}</p>
                    </div>
                </div>
                <div class="col-lg-4 col-md-6 all_column wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.5s">
                    <div class="feature-box position-relative all_boxes">
                        <h4>{{ __('site.home.proactive_3_title') }}</h4>
                        <p class="mb-0">{{ __('site.home.proactive_3_text') }}</p>
                    </div>
                </div>
            </div>
            <div class="float-left w-100 m-auto text-center wow fadeInUp" data-wow-duration="2s" data-wow-delay="0.4s">
                <a href="{{ \App\Support\SiteLocale::urlForPage('contact') }}" class="text-decoration-none primary_btn d-inline-block">{{ __('site.home.proactive_cta') }}</a>
            </div>
        </div>
    </section>

    <!-- VISITOR INTELLIGENCE SECTION -->
    <section class="float-left w-100 position-relative why-choose-us-con padding-top padding-bottom main-box">
        <div class="container wow fadeInUp" data-wow-duration="2s" data-wow-delay="0.2s">
            <div class="heading-title-con text-center">
                <span class="special-text color-blue d-block wow fadeInLeft" data-wow-duration="2s" data-wow-delay="0.2s">{{ __('site.home.visitor_label') }}</span>
                <h2 class="wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.4s">{{ __('site.home.visitor_title') }}</h2>
                <p class="mx-auto" style="max-width: 760px;">{{ __('site.home.visitor_text') }}</p>
            </div>
            <div class="choose-outer-con wow fadeInDown" data-wow-duration="2s" data-wow-delay="0.5s">
                <div class="choose-box">
                    <h6>{{ __('site.home.visitor_1_title') }}</h6>
                    <p class="mb-0">{{ __('site.home.visitor_1_text') }}</p>
                </div>
                <div class="choose-box">
                    <h6>{{ __('site.home.visitor_2_title') }}</h6>
                    <p class="mb-0">{{ __('site.home.visitor_2_text') }}</p>
                </div>
                <div class="choose-box">
                    <h6>{{ __('site.home.visitor_3_title') }}</h6>
                    <p class="mb-0">{{ __('site.home.visitor_3_text') }}</p>
                </div>
                <div class="choose-box">
                    <h6>{{ __('site.home.visitor_4_title') }}</h6>
                    <p class="mb-0">{{ __('site.home.visitor_4_text') }}</p>
                </div>
                <div class="choose-box">
                    <h6>{{ __('site.home.visitor_5_title') }}</h6>
                    <p class="mb-0">{{ __('site.home.visitor_5_text') }}</p>
                </div>
            </div>
            <div class="float-left w-100 m-auto text-center wow fadeInUp" data-wow-duration="2s" data-wow-delay="0.4s">
                <a href="{{ \App\Support\SiteLocale::urlForPage('contact') }}" class="text-decoration-none primary_btn d-inline-block">{{ __('site.home.visitor_cta') }}</a>
            </div>
        </div>
    </section>

    <!-- WHY CHOOSE US SECTION -->
    <section class="float-left w-100 position-relative why-choose-us-con padding-top padding-bottom main-box">
        <div class="container wow fadeInUp" data-wow-duration="2s" data-wow-delay="0.2s">
            <div class="heading-title-con text-center">
                <span class="special-text color-blue d-block wow fadeInLeft" data-wow-duration="2s"
                      data-wow-delay="0.2s">{{ __('site.home.why_label') }}</span>
                <h2 class="wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.4s">
                    {{ __('site.home.why_title') }}
                </h2>
                <!-- heading title con -->
            </div>
            <div class="choose-outer-con wow fadeInDown" data-wow-duration="2s" data-wow-delay="0.5s">
                <div class="choose-box">
                    <figure><img src="{{ asset('assets/images/choose-icon1.png')}}" alt="" class="img-fluid"></figure>
                    <h6>{{ __('site.home.why_1_title') }}</h6>
                    <p class="mb-0">
                        {{ __('site.home.why_1_text') }}
                    </p>
                    <!-- choose box -->
                </div>
                <div class="choose-box">
                    <figure><img src="{{ asset('assets/images/choose-icon2.png')}}" alt="" class="img-fluid"></figure>
                    <h6>{{ __('site.home.why_2_title') }}</h6>
                    <p class="mb-0">
                        {{ __('site.home.why_2_text') }}
                    </p>
                    <!-- choose box -->
                </div>
                <div class="choose-box">
                    <figure><img src="{{ asset('assets/images/choose-icon3.png')}}" alt="" class="img-fluid"></figure>
                    <h6>{{ __('site.home.why_3_title') }}</h6>
                    <p class="mb-0">
                        {{ __('site.home.why_3_text') }}
                    </p>
                    <!-- choose box -->
                </div>
                <div class="choose-box">
                    <figure><img src="{{ asset('assets/images/choose-icon4.png')}}" alt="" class="img-fluid"></figure>
                    <h6>{{ __('site.home.why_4_title') }}</h6>
                    <p class="mb-0">
                        {{ __('site.home.why_4_text') }}
                    </p>
                    <!-- choose box -->
                </div>
                <div class="choose-box">
                    <figure><img src="{{ asset('assets/images/choose-icon5.png')}}" alt="" class="img-fluid"></figure>
                    <h6>{{ __('site.home.why_5_title') }}</h6>
                    <p class="mb-0">
                        {{ __('site.home.why_5_text') }}
                    </p>
                    <!-- choose box -->
                </div>
                <!-- choose outer con -->
            </div>
            <div class="float-left w-100 m-auto text-center wow fadeInUp" data-wow-duration="2s" data-wow-delay="0.4s">
                <a href="{{ \App\Support\SiteLocale::urlForPage('about')}}" class="text-decoration-none primary_btn d-inline-block">{{ __('site.home.why_cta') }}</a>
            </div>
            <!-- container -->
        </div>
        <!-- why choose us  -->
    </section>

    @php
        $homePricingFeatureSets = [
            'core' => [
                __('site.services_details.knowledge_rag.name'), __('site.services_details.knowledge_rag.features.0.title'),
                __('site.services_details.knowledge_rag.features.1.title'), __('site.services_details.knowledge_rag.features.2.title'),
                __('site.services_details.knowledge_rag.features.3.title'), __('site.home.pricing_core_text'),
                __('site.pricing.start_core'), __('site.home.pricing_extensions'), __('site.home.pricing_title'),
            ],
            'extensions' => [
                'Community Basic +19 € / ' . __('site.home.per_month'), 'Community Pro +49 € / ' . __('site.home.per_month'),
                __('site.services.community_title'), 'Business Automation Basic +39 € / ' . __('site.home.per_month'),
                'Business Automation Pro +99 € / ' . __('site.home.per_month'), __('site.services_details.workflows_connecteurs.name'),
            ],
            'agents' => [
                'Agentics Basic +59 € / ' . __('site.home.per_month'), 'Agentics Pro +149 € / ' . __('site.home.per_month'),
                __('site.services_details.agents_ia.name'), __('site.services_details.agents_ia.features.1.title'),
                __('site.services_details.ai_sales_hunter.name'), __('site.pricing.evaluate_agents'),
            ],
        ];
    @endphp
    <!-- PRICING PLAN SECTION -->
    <section class="float-left w-100 position-relative pricing-plan-con padding-top padding-bottom main-box">
        <div class="container wow fadeInUp" data-wow-duration="2s" data-wow-delay="0.2s">
            <div class="heading-title-con text-center">
                <span class="special-text color-blue d-block wow fadeInLeft" data-wow-duration="2s"
                      data-wow-delay="0.4s">{{ __('site.home.pricing_label') }}</span>
                <h2 class="wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.5s">
                    {{ __('site.home.pricing_title') }}
                </h2>
                <p>{{ __('site.home.pricing_intro') }}</p>
                <!-- heading title con -->
            </div>
            <div class="row all_row wow fadeInDown" data-wow-duration="2s" data-wow-delay="0.5s">
                <div class="col-lg-4 col-md-6 all_column">
                    <div class="pricing-box w-100 all_boxes">
                        <div class="plan-content">
                            <h3 class="">{{ __('site.home.pricing_core') }}</h3>
                            <p>
                                {{ __('site.home.pricing_core_text') }}
                            </p>
                            <div class="generic-price d-inline-block">
                                <span class="d-block  starting-at">
                                    {{ __('site.home.pricing_from') }}
                                </span>
                                <sup class="d-inline-block  font-weight-normal">€</sup>
                                <span class="d-inline-block  price-text font-weight-600">29</span>
                                <span class="d-inline-block  per-month mb-0 position-relative font-weight-normal">
                                    {{ __('site.home.per_month') }}
                                </span>
                            </div>
                        </div>
                        <div class="plan-listing">
                            <ul class="list-unstyled p-0 ">
                                @foreach ($homePricingFeatureSets['core'] as $feature)
                                    <li class="position-relative"><i class="fa-solid fa-check"></i> {{ $feature }}</li>
                                @endforeach
                            </ul>
                            <a href="{{ \App\Support\SiteLocale::urlForPage('contact') }}" class="text-decoration-none primary_btn">{{ __('site.home.pricing_cta') }}</a>
                        </div>
                    </div>
                </div>
                <!-- 🟡 BASIC (NOUVEAU BLOC) -->
                <div class="col-lg-4 col-md-6 all_column">
                    <div class="el-default-pricing pricing-box w-100 all_boxes">
                        <div class="plan-content">
                            <h3 class="">{{ __('site.home.pricing_extensions') }}</h3>
                            <p>
                                {{ __('site.home.pricing_extensions_text') }}
                            </p>
                            <div class="generic-price d-inline-block">
                                <span class="d-block starting-at">{{ __('site.home.pricing_from') }}</span>
                                <sup class="d-inline-block font-weight-normal">€</sup>
                                <span class="d-inline-block price-text font-weight-600">19</span>
                                <span class="d-inline-block per-month mb-0 position-relative font-weight-normal">
                                    {{ __('site.home.per_month') }}
                                </span>
                            </div>
                        </div>

                        <div class="plan-listing">
                            <ul class="list-unstyled p-0 ">
                                @foreach ($homePricingFeatureSets['extensions'] as $feature)
                                    <li class="position-relative"><i class="fa-solid fa-check"></i> {{ $feature }}</li>
                                @endforeach
                            </ul>
                            <a href="{{ \App\Support\SiteLocale::urlForPage('contact') }}" class="text-decoration-none primary_btn">{{ __('site.home.pricing_cta') }}</a>
                        </div>
                    </div>
                </div>
                <!-- 🔵 PRO -->
                <div class="col-lg-4 col-md-6 all_column">
                    <div class="pricing-box w-100 all_boxes">
                        <div class="plan-content">
                            <h3 class="">{{ __('site.home.pricing_agents') }}</h3>
                            <p>
                                {{ __('site.home.pricing_agents_text') }}
                            </p>
                            <div class="generic-price d-inline-block">
                                <span class="d-block starting-at">
                                    {{ __('site.home.pricing_from') }}
                                </span>
                                <sup class="d-inline-block font-weight-normal">€</sup>
                                <span class="d-inline-block price-text font-weight-600">59</span>
                                <span class="d-inline-block per-month mb-0 position-relative font-weight-normal">
                                    {{ __('site.home.per_month') }}
                                </span>
                            </div>
                        </div>

                        <div class="plan-listing">
                            <ul class="list-unstyled p-0 ">
                                @foreach ($homePricingFeatureSets['agents'] as $feature)
                                    <li class="position-relative"><i class="fa-solid fa-check"></i> {{ $feature }}</li>
                                @endforeach
                            </ul>
                            <a href="{{ \App\Support\SiteLocale::urlForPage('contact') }}" class="text-decoration-none primary_btn">{{ __('site.home.pricing_cta') }}</a>
                        </div>
                    </div>
                </div>
            </div>
            <!-- container -->
        </div>
        <!-- pricing plan con -->
    </section>

    @php $homeFaqItems = array_slice(trans('site.faq.items'), 0, 4); @endphp
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
                      data-wow-delay="0.2s">{{ __('site.home.faq_label') }}</span>
                        <h2 class=" wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.4s">
                            {{ __('site.home.faq_title') }}
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
                                                    {{ __('site.home.faq_1') }}
                                                </h6>
                                            </a>
                                        </div>
                                        <div id="collapseOne" class="collapse" aria-labelledby="headingOne"
                                             data-parent="#faq_accordion1">
                                            <div class="card-body">
                                                <p class="text-size-16 text-left mb-0">{{ $homeFaqItems[0]['answer'] }}</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="accordion-card">
                                        <div class="card-header" id="headingTwo">
                                            <a href="#" class="btn btn-link collapsed" data-toggle="collapse"
                                               data-target="#collapseTwo" aria-expanded="false"
                                               aria-controls="collapseTwo">
                                                <h6>
                                                    {{ __('site.home.faq_2') }}
                                                </h6>
                                            </a>
                                        </div>
                                        <div id="collapseTwo" class="show collapse" aria-labelledby="headingTwo"
                                             data-parent="#faq_accordion1">
                                            <div class="card-body">
                                                <p class="text-size-16 text-left mb-0">{{ $homeFaqItems[1]['answer'] }}</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="accordion-card">
                                        <div class="card-header" id="headingThree">
                                            <a href="#" class="btn btn-link collapsed" data-toggle="collapse"
                                               data-target="#collapseThree" aria-expanded="false"
                                               aria-controls="collapseThree">
                                                <h6>{{ __('site.home.faq_3') }}</h6>
                                            </a>
                                        </div>
                                        <div id="collapseThree" class="collapse" aria-labelledby="headingThree"
                                             data-parent="#faq_accordion1">
                                            <div class="card-body">
                                                <p class="text-size-16 text-left mb-0">{{ $homeFaqItems[2]['answer'] }}</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="accordion-card">
                                        <div class="card-header" id="headingFour">
                                            <a href="#" class="btn btn-link collapsed" data-toggle="collapse"
                                               data-target="#collapseFour" aria-expanded="false"
                                               aria-controls="collapseFour">
                                                <h6>{{ __('site.home.faq_4') }}</h6>
                                            </a>
                                        </div>
                                        <div id="collapseFour" class="collapse" aria-labelledby="headingFour"
                                             data-parent="#faq_accordion1">
                                            <div class="card-body">
                                                <p class="text-size-16 text-left mb-0">{{ $homeFaqItems[3]['answer'] }}</p>
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
