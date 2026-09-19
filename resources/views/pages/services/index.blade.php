@extends('pages.layouts.blank')

@section('seo')
    @include('pages.partials.seo', ['page' => 'services'])
    {{--
    <!-- Primary Meta Tags -->
    <title>Capacités IA pour entreprises | RAG, workflows et agents | ELChat</title>

    <meta name="title" content="Capacités IA pour entreprises | RAG, workflows et agents | ELChat">

    <meta name="description"
          content="Découvrez les capacités ELChat : IA conversationnelle, RAG, Visitor Intelligence, engagement proactif, workflows, connecteurs et agents IA pour les entreprises.">

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
          content="Visitor Intelligence, RAG, engagement proactif, omnicanal, workflows, connecteurs et agents spécialisés réunis dans une plateforme d'IA opérationnelle.">

    <meta name="twitter:image"
          content="https://elchat.io/assets/images/sub-banner-img.png">
    
    --}}
@endsection

@section('structured-data')
    @php
        $capabilitySlugs = ['knowledge-rag', 'workflows-connecteurs', 'intelligence-business', 'engagement-proactif', 'visitor-intelligence', 'agents-ia', 'ai-sales-hunter'];
        $capabilities = array_map(static function (string $capabilitySlug): array {
            $capability = trans('site.services_details.' . str_replace('-', '_', $capabilitySlug));

            return [
                '@type' => 'ListItem',
                'position' => 0,
                'name' => $capability['name'],
                'description' => $capability['description'],
            ];
        }, $capabilitySlugs);
        foreach ($capabilities as $index => &$capability) {
            $capability['position'] = $index + 1;
        }
        unset($capability);
    @endphp
    <script type="application/ld+json">
        {!! json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            '@id' => \App\Support\SiteLocale::urlForPage('services') . '#capabilities',
            'name' => __('site.services.title'),
            'url' => \App\Support\SiteLocale::urlForPage('services'),
            'itemListElement' => $capabilities,
            /*
                [
                    '@type' => 'ListItem',
                    'position' => 1,
                    'name' => 'Knowledge Intelligence & RAG',
                    'description' => 'Base de connaissances et réponses fondées sur les sources de l’entreprise.',
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 2,
                    'name' => 'Workflows & connecteurs métier',
                    'description' => 'Automatisation des processus dans les outils autorisés.',
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 3,
                    'name' => 'Business & Executive Intelligence',
                    'description' => 'Analyse des événements et aide à la décision opérationnelle.',
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 4,
                    'name' => 'Engagement Proactif',
                    'description' => 'Reprise contextualisée des conversations lorsque les signaux le justifient.',
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 5,
                    'name' => 'Visitor Intelligence',
                    'description' => 'Lecture des parcours visiteurs, événements et replays terminés.',
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 6,
                    'name' => 'Agents IA spécialisés',
                    'description' => 'Agents orientés vers des objectifs précis avec autonomie configurable.',
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 7,
                    'name' => 'AI Sales Hunter',
                    'description' => 'Prospection et qualification encadrées par des règles configurables.',
                ],
            ],*/
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
    </script>
@endsection

@section('main-content')
    <!-- SUB BANNER SECTION -->
    <section class="float-left w-100 sub-banner-con position-relative main-box">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-7 col-md-7">
                    <div class="sub-banner-content-con">
                        <h1>{{ __('site.services.title') }}</h1>
                        <p>
                            {{ __('site.services.hero') }}
                        </p>
                        <div class="breadcrumb-con d-inline-block">
                            <ol class="breadcrumb mb-0">
                                <li class="breadcrumb-item"><a href="{{ \App\Support\SiteLocale::urlForPage('home') }}">{{ __('site.nav.home') }}</a></li>
                                <li class="breadcrumb-item active" aria-current="page">{{ __('site.nav.services') }}</li>
                            </ol>
                        </div>
                        <!-- sub banner content con -->
                    </div>

                    <!-- col -->
                </div>
                <div class="col-lg-5 col-md-5">
                    <div class="sub-banner-img-con">
                        <figure>
                            <img src="{{ asset('assets/images/sub-banner-img.png')}}" alt="Illustration des capacités IA d’ELChat">
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
                      data-wow-delay="0.2s">{{ __('site.services.label') }}</span>
                <h2 class="wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.4s">
                    {{ __('site.services.heading') }}
                </h2>
                <!-- heading title con -->
            </div>
            <div class="row all_row wow fadeIn" data-wow-duration="2s" data-wow-delay="0.4s">
                <div class="col-lg-4 col-md-6 all_column wow fadeInLeft" data-wow-duration="2s" data-wow-delay="0.5s">
                    <div class="feature-box position-relative all_boxes">
                        <h4>{{ __('site.services.rag_title') }}</h4>
                        <p class="mb-0">
                            {{ __('site.services.rag_text') }}
                        </p>
                        <img src="{{ asset('assets/images/feature-img1-icon1.png')}}" alt="Icône de base de connaissances RAG"
                             class="img-fluid position-absolute feature-icon1  wow fadeInUp" data-wow-duration="2s"
                             data-wow-delay="0.6s">

                        <figure><img src="{{ asset('assets/images/feature-img1.png')}}" alt="Illustration de la base de connaissances ELChat"
                                     class="img-fluid  wow fadeInDown" data-wow-duration="2s" data-wow-delay="0.7s">
                        </figure>
                        <a href="{{ \App\Support\SiteLocale::urlForPage('service', app()->getLocale(), 'knowledge-rag') }}" aria-label="{{ __('site.services.detail') }} {{ __('site.services_details.knowledge_rag.name') }}"><img src="{{ asset('assets/images/up-right-arrow.png')}}" alt=""
                                                                    class="img-fluid"></a>
                        <!-- feature box -->
                    </div>
                    <!-- col -->
                </div>
                <div class="col-lg-4 col-md-6 all_column wow fadeInUp" data-wow-duration="2s" data-wow-delay="0.5s">
                    <div class="feature-box position-relative all_boxes bg-green">
                        <h4>{{ __('site.services.community_title') }}</h4>
                        <p class="mb-0">
                            {{ __('site.services.community_text') }}
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
                        <a href="{{ \App\Support\SiteLocale::urlForPage('service', app()->getLocale(), 'workflows-connecteurs') }}" aria-label="{{ __('site.services.detail') }} {{ __('site.services.automation_title') }}"><img src="{{ asset('assets/images/up-right-arrow.png')}}" alt=""
                                                                   class="img-fluid"></a>
                        <!-- feature box -->
                    </div>
                    <!-- col -->
                </div>
                <div class="col-lg-4 col-md-6 all_column  wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.5s">
                    <div class="feature-box position-relative all_boxes">
                        <h4>{{ __('site.services.automation_title') }}</h4>
                        <p class="mb-0">
                            {{ __('site.services.automation_text') }}
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
                        <a href="{{ \App\Support\SiteLocale::urlForPage('service', app()->getLocale(), 'intelligence-business') }}" aria-label="{{ __('site.services.detail') }} {{ __('site.services_details.intelligence_business.name') }}"><img src="{{ asset('assets/images/up-right-arrow.png')}}" alt=""
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
                <span class="special-text color-blue d-block wow fadeInLeft" data-wow-duration="2s" data-wow-delay="0.2s">{{ __('site.services.proactive_title') }}</span>
                <h2 class="wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.4s">{{ __('site.services.proactive_title') }}</h2>
                <p class="mx-auto" style="max-width: 760px;">
                    {{ __('site.services.proactive_text') }}
                </p>
            </div>
            <div class="choose-outer-con wow fadeInDown" data-wow-duration="2s" data-wow-delay="0.5s">
                <div class="choose-box">
                    <h6>{{ __('site.services_details.engagement_proactif.features.0.title') }}</h6>
                    <p class="mb-0">{{ __('site.services_details.engagement_proactif.features.0.text') }}</p>
                </div>
                <div class="choose-box">
                    <h6>{{ __('site.services_details.engagement_proactif.features.1.title') }}</h6>
                    <p class="mb-0">{{ __('site.services_details.engagement_proactif.features.1.text') }}</p>
                </div>
                <div class="choose-box">
                    <h6>{{ __('site.services_details.engagement_proactif.features.2.title') }}</h6>
                    <p class="mb-0">{{ __('site.services_details.engagement_proactif.features.2.text') }}</p>
                </div>
                <div class="choose-box">
                    <h6>{{ __('site.services_details.engagement_proactif.features.3.title') }}</h6>
                    <p class="mb-0">{{ __('site.services_details.engagement_proactif.features.3.text') }}</p>
                </div>
                <div class="choose-box">
                    <h6>{{ __('site.home.proactive_3_title') }}</h6>
                    <p class="mb-0">{{ __('site.home.proactive_3_text') }}</p>
                </div>
            </div>
            <div class="float-left w-100 m-auto text-center wow fadeInUp" data-wow-duration="2s" data-wow-delay="0.4s">
                <a href="{{ \App\Support\SiteLocale::urlForPage('contact') }}" class="text-decoration-none primary_btn d-inline-block">{{ __('site.services.detail') }}</a>
                <a href="{{ \App\Support\SiteLocale::urlForPage('service', app()->getLocale(), 'engagement-proactif') }}" class="text-decoration-none secondary_btn d-inline-block ml-2">{{ __('site.services.detail') }}</a>
            </div>
        </div>
    </section>

    <!-- VISITOR INTELLIGENCE SECTION -->
    <section class="float-left w-100 position-relative why-choose-us-con padding-top padding-bottom main-box">
        <div class="container wow fadeInUp" data-wow-duration="2s" data-wow-delay="0.2s">
            <div class="heading-title-con text-center">
                <span class="special-text color-blue d-block wow fadeInLeft" data-wow-duration="2s" data-wow-delay="0.2s">{{ __('site.services.visitor_title') }}</span>
                <h2 class="wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.4s">{{ __('site.services.visitor_heading') }}</h2>
                <p class="mx-auto" style="max-width: 760px;">{{ __('site.services.visitor_text') }}</p>
            </div>
            <div class="choose-outer-con wow fadeInDown" data-wow-duration="2s" data-wow-delay="0.5s">
                <div class="choose-box">
                    <h6>{{ __('site.services_details.visitor_intelligence.features.0.title') }}</h6>
                    <p class="mb-0">{{ __('site.services_details.visitor_intelligence.features.0.text') }}</p>
                </div>
                <div class="choose-box">
                    <h6>{{ __('site.services_details.visitor_intelligence.features.1.title') }}</h6>
                    <p class="mb-0">{{ __('site.services_details.visitor_intelligence.features.1.text') }}</p>
                </div>
                <div class="choose-box">
                    <h6>{{ __('site.services_details.visitor_intelligence.features.2.title') }}</h6>
                    <p class="mb-0">{{ __('site.services_details.visitor_intelligence.features.2.text') }}</p>
                </div>
                <div class="choose-box">
                    <h6>{{ __('site.services_details.visitor_intelligence.features.3.title') }}</h6>
                    <p class="mb-0">{{ __('site.services_details.visitor_intelligence.features.3.text') }}</p>
                </div>
                <div class="choose-box">
                    <h6>{{ __('site.home.visitor_5_title') }}</h6>
                    <p class="mb-0">{{ __('site.home.visitor_5_text') }}</p>
                </div>
            </div>
            <div class="float-left w-100 m-auto text-center wow fadeInUp" data-wow-duration="2s" data-wow-delay="0.4s">
                <a href="{{ \App\Support\SiteLocale::urlForPage('contact') }}" class="text-decoration-none primary_btn d-inline-block">{{ __('site.services.detail') }}</a>
                <a href="{{ \App\Support\SiteLocale::urlForPage('service', app()->getLocale(), 'visitor-intelligence') }}" class="text-decoration-none secondary_btn d-inline-block ml-2">{{ __('site.services.detail') }}</a>
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
                        <figure><img src="{{ asset('assets/images/work-img.png')}}" alt="Illustration du fonctionnement des capacités ELChat" class="img-fluid"></figure>
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
                                  data-wow-delay="0.5s">{{ __('site.services.how_label') }}</span>
                            <h2 class="wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.6s">
                                {{ __('site.services.how_title') }}
                            </h2>
                            <!-- heading title con -->
                        </div>
                        <ul class="list-unstyled p-0">
                            <li class="position-relative d-flex align-items-center">
                                <span class="d-block color-blue">01</span>
                                <div class="work-content-inner-con">
                                    <h5>{{ __('site.services.step1') }}</h5>
                                    <p class="mb-0">{{ __('site.home.step1_text') }}</p>
                                    <!-- work content inner con -->
                                </div>
                            </li>
                            <li class="position-relative d-flex align-items-center">
                                <span class="d-block color-blue">02</span>
                                <div class="work-content-inner-con">
                                    <h5>{{ __('site.services.step2') }}</h5>
                                    <p class="mb-0">{{ __('site.home.step2_text') }}</p>
                                    <!-- work content inner con -->
                                </div>
                            </li>
                            <li class="position-relative d-flex align-items-center">
                                <span class="d-block color-blue">03</span>
                                <div class="work-content-inner-con">
                                    <h5>{{ __('site.services.step3') }}</h5>
                                    <p class="mb-0">{{ __('site.home.step3_text') }}</p>
                                    <!-- work content inner con -->
                                </div>
                            </li>
                        </ul>
                        <a href="{{ \App\Support\SiteLocale::urlForPage('contact') }}" class="text-decoration-none primary_btn d-inline-block">
                            {{ __('site.services.use_case') }}
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
                      data-wow-delay="0.2s">{{ __('site.services.why_label') }}</span>
                <h2 class="wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.4s">
                    {{ __('site.services.why_title') }}
                </h2>
                <!-- heading title con -->
            </div>
            @php
                $whySlugs = ['knowledge_rag', 'intelligence_business', 'agents_ia', 'ai_sales_hunter', 'workflows_connecteurs'];
            @endphp
            <div class="choose-outer-con wow fadeInDown" data-wow-duration="2s" data-wow-delay="0.5s">
                @foreach ($whySlugs as $index => $whySlug)
                    @php $whyCapability = trans('site.services_details.' . $whySlug); @endphp
                    <div class="choose-box">
                        <figure><img src="{{ asset('assets/images/choose-icon' . ($index + 1) . '.png')}}" alt="" class="img-fluid"></figure>
                        <h6>{{ $whyCapability['name'] }}</h6>
                        <p class="mb-0">{{ $whyCapability['intro'] }}</p>
                    </div>
                @endforeach
            </div>
            <div class="float-left w-100 m-auto text-center wow fadeInUp" data-wow-duration="2s" data-wow-delay="0.4s">
                <a href="{{ \App\Support\SiteLocale::urlForPage('about')}}" class="text-decoration-none primary_btn d-inline-block">{{ __('site.services.vision') }}</a>
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
        @php $serviceFaqItems = array_slice(trans('site.faq.items'), 0, 4); @endphp
        <div class="container wow fadeInUp" data-wow-duration="2s" data-wow-delay="0.2s">
            <div class="row ">
                <div class="col-xl-7 col-lg-10 col-12 mx-auto">
                    <div class="faq_content text-center">
                        <span class="special-text color-blue d-block wow fadeInLeft" data-wow-duration="2s"
                              data-wow-delay="0.2s">{{ __('site.faq.label') }}</span>
                        <h2 class=" wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.4s">
                            {{ __('site.faq.heading') }}
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
                                                <h6>{{ $serviceFaqItems[0]['question'] }}</h6>
                                            </a>
                                        </div>
                                        <div id="collapseOne" class="collapse" aria-labelledby="headingOne"
                                             data-parent="#faq_accordion1">
                                            <div class="card-body">
                                                <p class="text-size-16 text-left mb-0">{{ $serviceFaqItems[0]['answer'] }}</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="accordion-card">
                                        <div class="card-header" id="headingTwo">
                                            <a href="#" class="btn btn-link collapsed" data-toggle="collapse"
                                               data-target="#collapseTwo" aria-expanded="false"
                                               aria-controls="collapseTwo">
                                                <h6>{{ $serviceFaqItems[1]['question'] }}</h6>
                                            </a>
                                        </div>
                                        <div id="collapseTwo" class="show collapse" aria-labelledby="headingTwo"
                                             data-parent="#faq_accordion1">
                                            <div class="card-body">
                                                <p class="text-size-16 text-left mb-0">{{ $serviceFaqItems[1]['answer'] }}</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="accordion-card">
                                        <div class="card-header" id="headingThree">
                                            <a href="#" class="btn btn-link collapsed" data-toggle="collapse"
                                               data-target="#collapseThree" aria-expanded="false"
                                               aria-controls="collapseThree">
                                                <h6>{{ $serviceFaqItems[2]['question'] }}</h6>
                                            </a>
                                        </div>
                                        <div id="collapseThree" class="collapse" aria-labelledby="headingThree"
                                             data-parent="#faq_accordion1">
                                            <div class="card-body">
                                                <p class="text-size-16 text-left mb-0">{{ $serviceFaqItems[2]['answer'] }}</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="accordion-card">
                                        <div class="card-header" id="headingFour">
                                            <a href="#" class="btn btn-link collapsed" data-toggle="collapse"
                                               data-target="#collapseFour" aria-expanded="false"
                                               aria-controls="collapseFour">
                                                <h6>{{ $serviceFaqItems[3]['question'] }}</h6>
                                            </a>
                                        </div>
                                        <div id="collapseFour" class="collapse" aria-labelledby="headingFour"
                                             data-parent="#faq_accordion1">
                                            <div class="card-body">
                                                <p class="text-size-16 text-left mb-0">{{ $serviceFaqItems[3]['answer'] }}</p>
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
