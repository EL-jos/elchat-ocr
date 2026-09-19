@extends('pages.layouts.blank')

@section('seo')
    @include('pages.partials.seo', ['page' => 'about'])
    {{--
    <!-- Primary Meta Tags -->
    <title>À propos d’ELChat | IA opérationnelle pour entreprises</title>

    <meta name="title" content="À propos d’ELChat | IA opérationnelle pour entreprises">

    <meta name="description"
          content="Découvrez la vision d’ELChat : une IA opérationnelle qui relie connaissances, décisions, workflows, agents et outils métier pour les entreprises.">

    <meta name="author" content="ELChat">
    <meta name="robots" content="index, follow">

    <link rel="canonical" href="https://elchat.io/a-propos">

    <!-- Open Graph -->
    <meta property="og:type" content="website">
    <meta property="og:locale" content="fr_FR">
    <meta property="og:site_name" content="ELChat">

    <meta property="og:title"
          content="À propos d'ELChat | Transformer la connaissance en action maîtrisée">

    <meta property="og:description"
          content="ELChat relie connaissances, événements et outils métier pour aider les entreprises à comprendre, décider, agir, mesurer et apprendre avec l'IA.">

    <meta property="og:url"
          content="https://elchat.io/a-propos">

    <meta property="og:image"
          content="https://elchat.io/assets/images/sub-banner-img.png">

    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">

    <meta name="twitter:title"
          content="À Propos d'ELChat">

    <meta name="twitter:description"
          content="Découvrez pourquoi ELChat réunit connaissance, automatisation et agents IA dans une plateforme opérationnelle conçue pour les entreprises.">

    <meta name="twitter:image"
          content="https://elchat.io/assets/images/sub-banner-img.png">
    
    --}}
@endsection

@section('main-content')

    <!-- SUB BANNER SECTION -->
    <section class="float-left w-100 sub-banner-con position-relative main-box">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-7 col-md-7">
                    <div class="sub-banner-content-con">
                        <h1>{{ __('site.about.title') }}</h1>
                        <p>
                            {{ __('site.about.hero') }}
                        </p>
                        <div class="breadcrumb-con d-inline-block">
                            <ol class="breadcrumb mb-0">
                                <li class="breadcrumb-item"><a href="{{ \App\Support\SiteLocale::urlForPage('home') }}">{{ __('site.nav.home') }}</a></li>
                                <li class="breadcrumb-item active" aria-current="page">{{ __('site.nav.about') }}</li>
                            </ol>
                        </div>
                        <!-- sub banner content con -->
                    </div>

                    <!-- col -->
                </div>
                <div class="col-lg-5 col-md-5">
                    <div class="sub-banner-img-con">
                        <figure>
                            <img src="{{ asset('assets/images/sub-banner-img.png')}}" alt="Illustration de l’IA opérationnelle ELChat" class="">
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

    <!-- ABOUT US SECTION -->
    <section class="float-left w-100 about-us-con position-relative padding-top padding-bottom main-box">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-6 col-md-6 wow fadeInLeft" data-wow-duration="2s" data-wow-delay="0.2s">
                    <div class="about-us-img-con d-flex">
                        <figure><img src="{{ asset('assets/images/about-img1.jpg')}}" alt="image" class="img-fluid"></figure>
                        <figure class="abt-img2"><img src="{{ asset('assets/images/about-img2.jpg')}}" alt="image" class="img-fluid">
                        </figure>
                        <!-- about us img con -->
                    </div>
                    <!-- col -->
                </div>
                <div class="col-lg-6 col-md-6 wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.2s">
                    <div class="about-us-content-con">
                        <div class="heading-title-con mb-0">
                            <span class="special-text color-blue d-block wow fadeInLeft" data-wow-duration="2s"
                                  data-wow-delay="0.2s">{{ __('site.about.label') }}</span>
                            <h2 class="wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.2s">
                                {{ __('site.about.heading') }}
                            </h2>
                            <p class="wow fadeInLeft" data-wow-duration="2s" data-wow-delay="0.4s">
                                {{ __('site.about.p1') }}
                            </p>
                            <p class="wow fadeInLeft prgrph-2" data-wow-duration="2s" data-wow-delay="0.5s">
                                {{ __('site.about.p2') }}
                            </p>
                            <ul class="list-unstyled p-0 wow fadeInRight" data-wow-duration="2s"
                                data-wow-delay="0.6s">
                                <li class="position-relative"><i class="fa-solid fa-check"></i>
                                    {{ __('site.about.bullet1') }}
                                </li>
                                <li class="position-relative mb-0"><i class="fa-solid fa-check"></i>
                                    {{ __('site.about.bullet2') }}
                                </li>
                            </ul>
                            <a href="{{ \App\Support\SiteLocale::urlForPage('services') }}" class="text-decoration-none primary_btn d-inline-block wow
                                fadeInDown" data-wow-duration="2s" data-wow-delay="0.7s">{{ __('site.about.capabilities') }}</a>
                            <!-- heading title con -->
                        </div>
                        <!-- about us content con -->
                    </div>
                    <!-- col -->
                </div>
                <!-- row -->
            </div>
            <!-- container -->
        </div>
        <!-- about us con -->
    </section>

    <!-- STATISTICS SECTION -->
    <section class="float-left w-100 statistics-con position-relative padding-top padding-bottom main-box">
        <div class="container">
            <div class="row">
                <div class="col-lg-6 col-md-6 wow fadeInLeft" data-wow-duration="2s" data-wow-delay="0.2s">
                    <div class="statistics-content-con">
                        <div class="heading-title-con mb-0">
                            <span class="special-text color-blue d-block wow fadeInLeft" data-wow-duration="2s"
                                  data-wow-delay="0.4s">{{ __('site.about.approach') }}</span>
                            <h2 class="wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.5s">
                                {{ __('site.about.approach_title') }}
                            </h2>
                            <p class="wow fadeInLeft p-0" data-wow-duration="2s" data-wow-delay="0.6s">
                                {{ __('site.about.approach_text') }}
                            </p>

                            <a href="{{ \App\Support\SiteLocale::urlForPage('contact') }}" class="text-decoration-none primary_btn d-inline-block wow
                                fadeInDown" data-wow-duration="2s" data-wow-delay="0.6s">{{ __('site.about.context') }}</a>
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
                                    <span class="span-text d-block">{{ __('site.about.stat_platform') }}</span>
                                    <!-- statistics box -->
                                </div>
                                <!-- col -->
                            </div>
                            <div class="col-lg-6 col-md-6 d-flex">
                                <div class="statistics-box w-100">
                                    <figure><img src="{{ asset('assets/images/statistics-icon2.png')}}" alt="icon" class="img-fluid">
                                    </figure>
                                    <span class="d-inline-block black-text">3 </span>
                                    <!-- <span class="d-inline-block alphabet black-text">k</span> -->
                                    <span class="span-text d-block">{{ __('site.about.stat_modules') }}</span>
                                    <!-- statistics box -->
                                </div>
                                <!-- col -->
                            </div>
                            <div class="col-lg-6 col-md-6 d-flex">
                                <div class="statistics-box w-100">
                                    <figure><img src="{{ asset('assets/images/statistics-icon3.png')}}" alt="icon" class="img-fluid">
                                    </figure>
                                    <sup class="d-inline-block black-text"></sup><span
                                        class="d-inline-block black-text counter">6 </span><sup
                                        class="d-inline-block black-text"></sup>
                                    <span class="span-text d-block">{{ __('site.about.stat_steps') }}</span>
                                    <!-- statistics box -->
                                </div>
                                <!-- col -->
                            </div>
                            <div class="col-lg-6 col-md-6 d-flex">
                                <div class="statistics-box w-100">
                                    <figure><img src="{{ asset('assets/images/statistics-icon4.png')}}" alt="icon" class="img-fluid">
                                    </figure>
                                    <span class="d-inline-block black-text counter">29 </span><sup
                                        class="d-inline-block black-text">€</sup>
                                    <span class="span-text d-block">{{ __('site.about.stat_core') }}</span>
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

    {{--<!-- OUR TEAM SECTION -->
    <section class="float-left w-100 our-team-con position-relative padding-top main-box text-center">
        <div class="container wow fadeInUp" data-wow-duration="2s" data-wow-delay="0.2s">
            <div class="heading-title-con text-center">
                <span class="special-text color-blue d-block wow fadeInLeft" data-wow-duration="2s"
                      data-wow-delay="0.2s">Our Team</span>
                <h2 class="wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.4s">The Expert Team Behind <br>
                    Our Success</h2>
                <!-- heading title con -->
            </div>
            <div class="row all_row wow fadeInDown" data-wow-duration="2s" data-wow-delay="0.2s">
                <div class="col-lg-3 col-md-6 all_column wow fadeInDown" data-wow-duration="2s" data-wow-delay="0.2s">
                    <div class="team-box all_boxes">
                        <figure class="mb-0"><img src="assets/images/team-person1.jpg" alt="team" class="img-fluid">
                        </figure>
                        <h5 class="">Emily Carter</h5>
                        <span class="d-block">Chief Executive Officer</span>
                        <ul class="list-unstyled mb-0 social-icons">
                            <li><a href="https://www.facebook.com/" class="text-decoration-none"><i
                                        class="fa-brands fa-facebook-f social-networks"></i></a></li>
                            <li><a href="https://www.instagram.com/" class="text-decoration-none"><i
                                        class="fa-brands fa-instagram social-networks"></i></a></li>
                            <li><a href="https://www.linkedin.com/" class="text-decoration-none"><i
                                        class="fa-brands fa-linkedin-in social-networks"></i></a></li>
                        </ul>
                        <!-- team box -->
                    </div>

                    <!-- col -->
                </div>
                <div class="col-lg-3 col-md-6 all_column wow fadeInDown" data-wow-duration="2s" data-wow-delay="0.4s">
                    <div class="team-box all_boxes">
                        <figure class="mb-0"><img src="assets/images/team-person2.jpg" alt="team" class="img-fluid">
                        </figure>
                        <h5 class="">James Thompson</h5>
                        <span class="d-block">Head of Product</span>
                        <ul class="list-unstyled mb-0 social-icons">
                            <li><a href="https://www.facebook.com/" class="text-decoration-none"><i
                                        class="fa-brands fa-facebook-f social-networks"></i></a></li>
                            <li><a href="https://www.instagram.com/" class="text-decoration-none"><i
                                        class="fa-brands fa-instagram social-networks"></i></a></li>
                            <li><a href="https://www.linkedin.com/" class="text-decoration-none"><i
                                        class="fa-brands fa-linkedin-in social-networks"></i></a></li>
                        </ul>
                        <!-- team box -->
                    </div>

                    <!-- col -->
                </div>
                <div class="col-lg-3 col-md-6 all_column wow fadeInDown" data-wow-duration="2s" data-wow-delay="0.5s">
                    <div class="team-box all_boxes">
                        <figure class="mb-0"><img src="assets/images/team-person3.jpg" alt="team" class="img-fluid">
                        </figure>
                        <h5 class="">Daniel Reed</h5>
                        <span class="d-block">Lead Software Engineer</span>
                        <ul class="list-unstyled mb-0 social-icons">
                            <li><a href="https://www.facebook.com/" class="text-decoration-none"><i
                                        class="fa-brands fa-facebook-f social-networks"></i></a></li>
                            <li><a href="https://www.instagram.com/" class="text-decoration-none"><i
                                        class="fa-brands fa-instagram social-networks"></i></a></li>
                            <li><a href="https://www.linkedin.com/" class="text-decoration-none"><i
                                        class="fa-brands fa-linkedin-in social-networks"></i></a></li>
                        </ul>
                        <!-- team box -->
                    </div>

                    <!-- col -->
                </div>
                <div class="col-lg-3 col-md-6 all_column wow fadeInDown" data-wow-duration="2s" data-wow-delay="0.6s">
                    <div class="team-box all_boxes">
                        <figure class="mb-0"><img src="assets/images/team-person4.jpg" alt="team" class="img-fluid">
                        </figure>
                        <h5 class="">Olivia Brook</h5>
                        <span class="d-block">Dirctor</span>
                        <ul class="list-unstyled mb-0 social-icons">
                            <li><a href="https://www.facebook.com/" class="text-decoration-none"><i
                                        class="fa-brands fa-facebook-f social-networks"></i></a></li>
                            <li><a href="https://www.instagram.com/" class="text-decoration-none"><i
                                        class="fa-brands fa-instagram social-networks"></i></a></li>
                            <li><a href="https://www.linkedin.com/" class="text-decoration-none"><i
                                        class="fa-brands fa-linkedin-in social-networks"></i></a></li>
                        </ul>
                        <!-- team box -->
                    </div>

                    <!-- col -->
                </div>

                <!--  -->
            </div>
            <!-- container -->
        </div>
    </section>--}}

@endsection
