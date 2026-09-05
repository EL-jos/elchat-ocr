@extends('pages.layouts.blank')

@section('seo')
    <!-- Primary Meta Tags -->
    <title>Contact ELChat | Demander une démo de la plateforme IA</title>

    <meta name="title" content="Contact ELChat | Demander une démo de la plateforme IA">

    <meta name="description"
          content="Demandez une démonstration d'ELChat et échangez sur vos cas d'usage : connaissance RAG, workflows, connecteurs métier, agents IA et pilotage des résultats.">

    <meta name="keywords"
          content="contact ELChat, démo ELChat, plateforme IA entreprise, devis automatisation métier, agents IA, workflows IA, RAG entreprise, cadrage IA">

    <meta name="author" content="ELChat">
    <meta name="robots" content="index, follow">

    <link rel="canonical" href="https://elchat.io/contact">

    <!-- Open Graph -->
    <meta property="og:type" content="website">
    <meta property="og:locale" content="fr_FR">
    <meta property="og:site_name" content="ELChat">

    <meta property="og:title"
          content="Contact ELChat | Demander une démonstration">

    <meta property="og:description"
          content="Présentez votre contexte à l'équipe ELChat et identifiez les connaissances, processus et outils que l'IA peut relier utilement.">

    <meta property="og:url"
          content="https://elchat.io/contact">

    <meta property="og:image"
          content="https://elchat.io/assets/images/sub-banner-img.png">

    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">

    <meta name="twitter:title"
          content="Contact ELChat">

    <meta name="twitter:description"
          content="Demandez une démonstration d'ELChat adaptée aux priorités opérationnelles de votre entreprise.">

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
                        <h1>Demandez une démo d’ELChat</h1>
                        <p>
                            Expliquez-nous votre objectif, vos sources de connaissance et les outils concernés.<br>
                            Nous vous aiderons à identifier un premier périmètre utile, mesurable et maîtrisé.
                        </p>
                        <div class="breadcrumb-con d-inline-block">
                            <ol class="breadcrumb mb-0">
                                <li class="breadcrumb-item"><a href="{{ route('home.page')}}">Accueil</a></li>
                                <li class="breadcrumb-item active" aria-current="page">Contact</li>
                            </ol>
                        </div>
                        <!-- sub banner content con -->
                    </div>

                    <!-- col -->
                </div>
                <div class="col-lg-5 col-md-5">
                    <div class="sub-banner-img-con">
                        <figure>
                            <img src="{{ asset('assets/images/sub-banner-img.png')}}" alt="robot" class="">
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

    <!-- CONTACT INFO SECTION -->
    <section class="float-left w-100 position-relative contact-info-con padding-top padding-bottom main-box">
        <div class="container">
            <div class="heading-title-con text-center">
                <span class="special-text color-blue d-block wow fadeInLeft" data-wow-duration="2s"
                      data-wow-delay="0.2s">Contact ELChat</span>
                <h2 class="wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.4s">
                     Partons de vos priorités<br>
                     et de vos processus réels
                </h2>
                <!-- heading title con -->
            </div>
            <div class="row all_row">
                <div class="col-lg-3 col-md-6 all_column wow fadeInDown" data-wow-duration="2s" data-wow-delay="0.4s">
                    <div class="contact-info-box all_boxes">
                        <figure><img src="{{ asset('assets/images/location-icon.png')}}" alt="location" class="img-fluid"></figure>
                        <h6>Localisation:</h6>
                        <p class="mb-0">
                            Casablanca, Maroc
                        </p>
                        <!-- contact info box -->
                    </div>
                    <!-- col -->
                </div>
                <div class="col-lg-3 col-md-6 all_column wow fadeInDown" data-wow-duration="2s" data-wow-delay="0.5s">
                    <div class="contact-info-box all_boxes">
                        <figure><img src="{{ asset('assets/images/email-icon.png')}}" alt="email" class="img-fluid"></figure>
                        <h6>Email:</h6>
                        <a href="mailto:contact@elchat.io" class="d-inline-block">
                            contact@elchat.io
                        </a>
                        {{--<div class="clearfix"></div>
                        <a href="mailto:aivio@gmail.com" class="d-inline-block">aivio@gmail.com</a>--}}
                        <!-- contact info box -->
                    </div>
                    <!-- col -->
                </div>
                <div class="col-lg-3 col-md-6 all_column wow fadeInDown" data-wow-duration="2s" data-wow-delay="0.6s">
                    <div class="contact-info-box all_boxes">
                        <figure><img src="{{ asset('assets/images/phone-icon.png')}}" alt="phone" class="img-fluid"></figure>
                        <h6>Téléphones:</h6>
                        <a href="tel:+33652233359" class="d-inline-block">+33 652 233 359
                        </a>
                        <div class="clearfix"></div>
                        <a href="tel:+212633628578" class="d-inline-block"> +212 633 628 578
                        </a>
                        <!-- contact info box -->
                    </div>
                    <!-- col -->
                </div>
                <div class="col-lg-3 col-md-6 all_column wow fadeInDown" data-wow-duration="2s" data-wow-delay="0.7s">
                    <div class="contact-info-box all_boxes">
                        <figure><img src="{{ asset('assets/images/busines-hours.png')}}" alt="hours" class="img-fluid"></figure>
                        <h6>Disponibilité:</h6>
                        <p class="mb-0">
                            Support et démonstrations<br>
                            du lundi au vendredi
                        </p>
                        <!-- contact info box -->
                    </div>
                    <!-- col -->
                </div>
                <!-- row -->
            </div>
            <!-- container -->
        </div>
        <!-- contact info con -->
    </section>

    <!-- CONTACT FORM SECTION-->
    <section class="float-left w-100 position-relative contact-form-con padding-top padding-bottom main-box">
        <div class="container wow fadeInUp" data-wow-duration="2s" data-wow-delay="0.2s">
            <div class="heading-title-con text-center">
            <span class="special-text color-blue d-block wow fadeInLeft" data-wow-duration="2s"
                  data-wow-delay="0.2s">Votre contexte</span>
                <h2 class="wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.4s">
                     Quel résultat souhaitez-vous<br>
                     obtenir avec l’IA ?
                </h2>
            </div>
            <div class="row wow fadeInDown" data-wow-duration="2s" data-wow-delay="0.4s">
                <div class="col-xl-12 col-lg-12 mr-auto ml-auto">
                    <form class="main-form text-center" method="post" id="contactpage">
                        @csrf
                        <ul class="list-unstyled p-0 float-left w-100 mb-0">
                            <li>
                                <input type="text" placeholder="Nom et entreprise" name="fname" id="fname">
                            </li>
                            <li>
                                <input type="tel" placeholder="Téléphone" name="phone" id="phone">
                            </li>
                            <li>
                                <input type="email" placeholder="Email" name="email" id="email">
                            </li>
                            <li>
                                <textarea placeholder="Décrivez votre objectif, les outils concernés et le processus à améliorer" rows="6" name="msg" id="msg"></textarea>
                            </li>
                        </ul>
                        <div id="form_result" style="display:none;margin-bottom:20px;"></div>
                        <div class="d-inline-block">
                            <button type="submit" id="submitBtn" class="primary_btn">Envoyer ma demande <i
                                        class="fas fa-arrow-right ml-2"></i></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </section>

    <!-- MAP SECTION -->
    <div class="float-left w-100 contact-map-con position-relative padding-top padding-bottom">
        <div class="container p-0 wow fadeInDown" data-wow-duration="2s" data-wow-delay="0.2s">
            <iframe
                src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3323.812345678901!2d-7.618777!3d33.589886!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0xda7d7c123456789%3A0xabcdef1234567890!2sCasablanca%2C%20Maroc!5e0!3m2!1sfr!2sma!4v0000000000000"
                allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade">
            </iframe>
            <!-- container fluid -->
        </div>
        <!-- contact map con -->
    </div>
    <div class="clearfix"></div>
@endsection

@section('scripts')
    @parent
    <script src="{{ asset('assets/js/contact-validate.js')}}"></script>
    <script src="{{ asset('assets/js/contact-form.js')}}"></script>
@endsection
