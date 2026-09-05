@php
    $routeName = request()->route()->getName()
@endphp

<!DOCTYPE html>
<html lang="fr">

<head>
    @yield('seo')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <!-- /SEO Ultimate -->
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0">
    <meta charset="utf-8">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('assets/images/favicon/apple-touch-icon.png')}}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('assets/images/favicon/favicon-32x32.png')}}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('assets/images/favicon/favicon-16x16.png')}}">
    <link rel="manifest" href="{{ asset('assets/images/favicon/site.webmanifest')}}">
    <meta name="msapplication-TileColor" content="#ffffff">
    <meta name="msapplication-TileImage" content="../ms-icon-144x144.html">
    <meta name="theme-color" content="#ffffff">
    <!-- Latest compiled and minified CSS -->
    <link href="{{ asset('assets/bootstrap/bootstrap.min.css')}}" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('assets/js/bootstrap.min.js')}}">
    <!-- Font Awesome link -->
    <link rel="stylesheet" href="{{ asset('assets/font-awesome/6.5.1/css/all.min.css')}}">
    <!-- StyleSheet link CSS -->
    <link href="{{ asset('assets/css/style.css')}}" rel="stylesheet" type="text/css">
    <link href="{{ asset('assets/css/responsive.css')}}" rel="stylesheet" type="text/css">
    <link href="{{ asset('assets/css/owl.carousel.min.css')}}" rel="stylesheet" type="text/css">
    <link href="{{ asset('assets/css/owl.theme.default.min.css')}}" rel="stylesheet" type="text/css">
    <!-- StyleSheet link CSS -->
    <link rel="stylesheet" href="{{ asset('assets/css/animate.css')}}">
    <link rel="stylesheet" href="{{ asset('assets/css/magnific-popup.css')}}" type="text/css">
    <link rel="stylesheet" href="{{ asset('assets/css/el-style.css?v=1.0.2')}}" type="text/css">
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>

<body>
<!-- Back to top button -->
<a id="button"></a>
<!-- HEADER SECTION -->
<header class="w-100 float-left header-con position-relative main-box">
    <div class="container">
        <nav class="navbar navbar-expand-lg navbar-light">
            <a class="navbar-brand" href="{{ route('home.page') }}">
                <figure class="mb-0">
                    <img src="{{ asset('assets/images/logo.svg')}}" alt="logo-icon">
                </figure>
            </a>
            <button class="navbar-toggler collapsed" type="button" data-toggle="collapse"
                    data-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false"
                    aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
                <span class="navbar-toggler-icon"></span>
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarSupportedContent">
                <ul class="navbar-nav ml-auto">

                    <li class="nav-item">
                        <a @class(["nav-link p-0", "active" => $routeName === 'home.page']) href="{{ route('home.page') }}">Accueil</a>
                    </li>

                    <li class="nav-item">
                        <a @class(["nav-link p-0", "active" => $routeName === 'about.page']) href="{{ route('about.page') }}">À propos</a>
                    </li>
                    <li class="nav-item">
                        <a @class(["nav-link p-0", "active" => $routeName === 'services.page']) href="{{ route('services.page') }}">Capacités</a>
                    </li>

                    <li class="nav-item">
                        <a @class(["nav-link p-0", "active" => $routeName === 'tarifs']) href="{{ route('abonnements.page') }}">Tarifs</a>
                    </li>
                    <li class="nav-item">
                        <a @class(["nav-link p-0", "active" => $routeName === 'faqs.page']) href="{{ route('faqs.page') }}">FAQ</a>
                    </li>
                    <li class="nav-item free-trial"><a class="nav-link font-weight-700" href="">Essayer gratuitement</a></li>
                </ul>

                <!-- navbar collapse -->
            </div>
            <div class="header-contact">
                <ul class="list-unstyled mb-0">
                    <!-- <li class="d-inline-block free-trial"><a class="text-white" href="contact.html">Try Free
                                Trial</a></li> -->
                    <li class="d-inline-block"><a href="{{ route('contact.page') }}" class="contact-btn d-inline-block">Demander une démo</a></li>
                    <!-- list unstyled -->
                </ul>
                <!-- header contact -->
            </div>
        </nav>
        <!-- container -->
    </div>
    <!-- header-con -->
</header>
<!--  -->

@yield('main-content')

<!-- TESTIMONIALS SECTION -->
{{--<section class="float-left w-100 testimonials-con position-relative padding-top padding-bottom main-box">
    <div class="container-fluid">
        <div class="heading-title-con text-center">
                <span class="special-text color-blue d-block wow fadeInLeft" data-wow-duration="2s"
                      data-wow-delay="0.2s">Testimonials</span>
            <h2 class="wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.4s">Build Trust With Reviews
                <br>
                Loved by Businesses Worldwide</h2>
            <!-- heading title con -->
        </div>
        <div class="row position-relative  wow fadeIn" data-wow-duration="2s" data-wow-delay="0.4s">
            <div class="owl-carousel owl-theme">
                <div class="item">
                    <div class="testimonial-box">
                        <figure><img src="assets/images/star-icon.png" alt="stars" class="img-fluid"></figure>
                        <p>Since adding the chatbot, our
                            customer support times have
                            dropped by over 50%. It's like
                            having a support!</p>
                        <span class="d-block customer font-weight-600">Rolex Trought</span>
                        <span class="d-block designation font-weight-500">Happy Client</span>
                        <!-- testimonial box -->
                    </div>
                    <!-- item -->
                </div>
                <div class="item">
                    <div class="testimonial-box">
                        <figure><img src="assets/images/star-icon.png" alt="stars" class="img-fluid"></figure>
                        <p>We were amazed at how easy it
                            was to set up. Within days, the chatbot
                            was handling real conversations and
                            doing it well.</p>
                        <span class="d-block customer font-weight-600">Alina Jame</span>
                        <span class="d-block designation font-weight-500">Happy Client</span>
                        <!-- testimonial box -->
                    </div>
                    <!-- item -->
                </div>
                <div class="item">
                    <div class="testimonial-box">
                        <figure><img src="assets/images/star-icon.png" alt="stars" class="img-fluid"></figure>
                        <p>Our website engagement shot up
                            after installing the chatbot. Visitors
                            now stay longer and actually get
                            the answers they need instantly.</p>
                        <span class="d-block customer font-weight-600">Kevin Andrew</span>
                        <span class="d-block designation font-weight-500">Happy Client</span>
                        <!-- testimonial box -->
                    </div>
                    <!-- item -->
                </div>
                <div class="item">
                    <div class="testimonial-box">
                        <figure><img src="assets/images/star-icon.png" alt="stars" class="img-fluid"></figure>
                        <p>We serve customers in three time
                            zones, and this chatbot handles it
                            all. No more missed messages or
                            delayed replies.</p>
                        <span class="d-block customer font-weight-600">Nazish Ehtaon</span>
                        <span class="d-block designation font-weight-500">Happy Client</span>
                        <!-- testimonial box -->
                    </div>
                    <!-- item -->
                </div>
                <div class="item">
                    <div class="testimonial-box">
                        <figure><img src="assets/images/star-icon.png" alt="stars" class="img-fluid"></figure>
                        <p>What impressed me most was how
                            natural the chatbot sounds—and we
                            were able to fully match it to our
                            brand voice</p>
                        <span class="d-block customer font-weight-600">John Clark</span>
                        <span class="d-block designation font-weight-500">Happy Client</span>
                        <!-- testimonial box -->
                    </div>
                    <!-- item -->
                </div>
                <div class="item">
                    <div class="testimonial-box">
                        <figure><img src="assets/images/star-icon.png" alt="stars" class="img-fluid"></figure>
                        <p>It’s not just for support. Our AI
                            chatbot has become a key sales
                            tool, helping qualify leads and guide
                            users through purchases.</p>
                        <span class="d-block customer font-weight-600">Zampa Devo</span>
                        <span class="d-block designation font-weight-500">Happy Client</span>
                        <!-- testimonial box -->
                    </div>
                    <!-- item -->
                </div>
                <!--  -->
                <!--  -->
                <div class="item">
                    <div class="testimonial-box">
                        <figure><img src="assets/images/star-icon.png" alt="stars" class="img-fluid"></figure>
                        <p>Since adding the chatbot, our
                            customer support times have
                            dropped by over 50%. It's like
                            having a support!</p>
                        <span class="d-block customer font-weight-600">Rolex Trought</span>
                        <span class="d-block designation font-weight-500">Happy Client</span>
                        <!-- testimonial box -->
                    </div>
                    <!-- item -->
                </div>
                <div class="item">
                    <div class="testimonial-box">
                        <figure><img src="assets/images/star-icon.png" alt="stars" class="img-fluid"></figure>
                        <p>We were amazed at how easy it
                            was to set up. Within days, the chatbot
                            was handling real conversations and
                            doing it well.</p>
                        <span class="d-block customer font-weight-600">Alina Jame</span>
                        <span class="d-block designation font-weight-500">Happy Client</span>
                        <!-- testimonial box -->
                    </div>
                    <!-- item -->
                </div>
                <div class="item">
                    <div class="testimonial-box">
                        <figure><img src="assets/images/star-icon.png" alt="stars" class="img-fluid"></figure>
                        <p>Our website engagement shot up
                            after installing the chatbot. Visitors
                            now stay longer and actually get
                            the answers they need instantly.</p>
                        <span class="d-block customer font-weight-600">Kevin Andrew</span>
                        <span class="d-block designation font-weight-500">Happy Client</span>
                        <!-- testimonial box -->
                    </div>
                    <!-- item -->
                </div>
                <div class="item">
                    <div class="testimonial-box">
                        <figure><img src="assets/images/star-icon.png" alt="stars" class="img-fluid"></figure>
                        <p>We serve customers in three time
                            zones, and this chatbot handles it
                            all. No more missed messages or
                            delayed replies.</p>
                        <span class="d-block customer font-weight-600">Nazish Ehtaon</span>
                        <span class="d-block designation font-weight-500">Happy Client</span>
                        <!-- testimonial box -->
                    </div>
                    <!-- item -->
                </div>
                <div class="item">
                    <div class="testimonial-box">
                        <figure><img src="assets/images/star-icon.png" alt="stars" class="img-fluid"></figure>
                        <p>What impressed me most was how
                            natural the chatbot sounds—and we
                            were able to fully match it to our
                            brand voice</p>
                        <span class="d-block customer font-weight-600">John Clark</span>
                        <span class="d-block designation font-weight-500">Happy Client</span>
                        <!-- testimonial box -->
                    </div>
                    <!-- item -->
                </div>
                <div class="item">
                    <div class="testimonial-box">
                        <figure><img src="assets/images/star-icon.png" alt="stars" class="img-fluid"></figure>
                        <p>It’s not just for support. Our AI
                            chatbot has become a key sales
                            tool, helping qualify leads and guide
                            users through purchases.</p>
                        <span class="d-block customer font-weight-600">Zampa Devo</span>
                        <span class="d-block designation font-weight-500">Happy Client</span>
                        <!-- testimonial box -->
                    </div>
                    <!-- item -->
                </div>
                <!--  -->
                <!--  -->
                <div class="item">
                    <div class="testimonial-box">
                        <figure><img src="assets/images/star-icon.png" alt="stars" class="img-fluid"></figure>
                        <p>Since adding the chatbot, our
                            customer support times have
                            dropped by over 50%. It's like
                            having a support!</p>
                        <span class="d-block customer font-weight-600">Rolex Trought</span>
                        <span class="d-block designation font-weight-500">Happy Client</span>
                        <!-- testimonial box -->
                    </div>
                    <!-- item -->
                </div>
                <div class="item">
                    <div class="testimonial-box">
                        <figure><img src="assets/images/star-icon.png" alt="stars" class="img-fluid"></figure>
                        <p>We were amazed at how easy it
                            was to set up. Within days, the chatbot
                            was handling real conversations and
                            doing it well.</p>
                        <span class="d-block customer font-weight-600">Alina Jame</span>
                        <span class="d-block designation font-weight-500">Happy Client</span>
                        <!-- testimonial box -->
                    </div>
                    <!-- item -->
                </div>
                <div class="item">
                    <div class="testimonial-box">
                        <figure><img src="assets/images/star-icon.png" alt="stars" class="img-fluid"></figure>
                        <p>Our website engagement shot up
                            after installing the chatbot. Visitors
                            now stay longer and actually get
                            the answers they need instantly.</p>
                        <span class="d-block customer font-weight-600">Kevin Andrew</span>
                        <span class="d-block designation font-weight-500">Happy Client</span>
                        <!-- testimonial box -->
                    </div>
                    <!-- item -->
                </div>
                <div class="item">
                    <div class="testimonial-box">
                        <figure><img src="assets/images/star-icon.png" alt="stars" class="img-fluid"></figure>
                        <p>We serve customers in three time
                            zones, and this chatbot handles it
                            all. No more missed messages or
                            delayed replies.</p>
                        <span class="d-block customer font-weight-600">Nazish Ehtaon</span>
                        <span class="d-block designation font-weight-500">Happy Client</span>
                        <!-- testimonial box -->
                    </div>
                    <!-- item -->
                </div>
                <div class="item">
                    <div class="testimonial-box">
                        <figure><img src="assets/images/star-icon.png" alt="stars" class="img-fluid"></figure>
                        <p>What impressed me most was how
                            natural the chatbot sounds—and we
                            were able to fully match it to our
                            brand voice</p>
                        <span class="d-block customer font-weight-600">John Clark</span>
                        <span class="d-block designation font-weight-500">Happy Client</span>
                        <!-- testimonial box -->
                    </div>
                    <!-- item -->
                </div>
                <div class="item">
                    <div class="testimonial-box">
                        <figure><img src="assets/images/star-icon.png" alt="stars" class="img-fluid"></figure>
                        <p>It’s not just for support. Our AI
                            chatbot has become a key sales
                            tool, helping qualify leads and guide
                            users through purchases.</p>
                        <span class="d-block customer font-weight-600">Zampa Devo</span>
                        <span class="d-block designation font-weight-500">Happy Client</span>
                        <!-- testimonial box -->
                    </div>
                    <!-- item -->
                </div>
                <!-- owl carousel -->
            </div>
            <!-- row -->
        </div>
        <!-- container -->
    </div>
</section>--}}

<!-- CALL TO ACTION -->
<section class="float-left w-100 position-relative call-to-action-con main-box padding-bottom">
    <div class="container wow fadeInUp" data-wow-duration="2s" data-wow-delay="0.2s">
        <div class="cta-inner-con padding-top100 padding-bottom100 position-relative">
            <figure><img src="{{ asset('assets/images/robot1.png')}}" alt="vector"
                         class="img-fluid position-absolute robot1 animated-robot"></figure>
            <figure><img src="{{ asset('assets/images/robot2.png')}}" alt="vector"
                         class="img-fluid position-absolute robot2 animated-robot"></figure>
            <div class="heading-title-con text-center mb-0">
                    <span class="special-text color-blue d-block wow fadeInLeft" data-wow-duration="2s"
                           data-wow-delay="0.2s">Votre premier cas d’usage</span>
                <h2 class="wow fadeInRight" data-wow-duration="2s" data-wow-delay="0.4s">
                    Prêt à relier vos connaissances,<br> vos outils et vos décisions ?
                </h2>
                <p class="wow fadeInDown" data-wow-duration="2s" data-wow-delay="0.5s">
                    Identifions un processus concret, les données nécessaires et le bon niveau d’autonomie. <br>
                    Vous pourrez ensuite mesurer le résultat avant d’étendre ELChat à d’autres équipes.
                </p>
                <a href="{{ route('about.page') }}" class="text-decoration-none primary_btn d-inline-block wow fadeInLeft"
                   data-wow-duration="2s" data-wow-delay="0.6s">Découvrir notre approche</a>
                <a href="{{ route('contact.page') }}" class="text-decoration-none secondary_btn d-inline-block wow fadeInRight"
                   data-wow-duration="2s" data-wow-delay="0.7s">Demander une démo</a>
                <!-- heading title con -->
            </div>
            <!-- cta inner con -->
        </div>
        <!-- container -->
    </div>
</section>

<!-- FOOTER SECTION -->
<section class="footer-con position-relative float-left w-100 main-box">
    <div class="container">
        <div class="middle_portion">
            <div class="row">
                <div class="col-xl-3 col-lg-3 col-md-12 col-sm-12 col-12">
                    <div class="logo-content">
                        <a href="{{ route('home.page') }}">
                            <figure class="footer-logo">
                                <img src="{{ asset('assets/images/logo.svg')}}" alt="image" class="img-fluid">
                            </figure>
                        </a>
                        <p class="text-size-16 text">
                            ELChat est une plateforme d’IA opérationnelle qui relie connaissances, événements, outils métier,
                            workflows et agents spécialisés pour transformer le contexte en décisions et actions mesurables.
                        </p>
                        <ul class="list-unstyled mb-0 social-icons">
                            <li><a href="https://www.facebook.com/" class="text-decoration-none"><i
                                        class="fa-brands fa-facebook-f social-networks"></i></a></li>
                            <li><a href="https://www.instagram.com/" class="text-decoration-none"><i
                                        class="fa-brands fa-instagram social-networks"></i></a></li>
                            <li><a href="https://www.linkedin.com/" class="text-decoration-none"><i
                                        class="fa-brands fa-linkedin-in social-networks"></i></a></li>
                        </ul>
                    </div>
                </div>
                <div class="col-xl-2 col-lg-2 col-md-3 col-sm-6 col-5">
                    <div class="links">
                        <h4 class="heading">Navigation</h4>
                        <ul class="list-unstyled mb-0">
                            <li><i class="fa-solid fa-arrow-right"></i><a href="{{ route('about.page') }}"
                                                                          class="text-decoration-none">À propos</a></li>
                            <li><i class="fa-solid fa-arrow-right"></i><a href="{{ route('services.page')}}"
                                                                           class="text-decoration-none">Capacités</a></li>
                            <li><i class="fa-solid fa-arrow-right"></i><a href="{{ route('faqs.page') }}"
                                                                           class="text-decoration-none">FAQ</a></li>
                            <li><i class="fa-solid fa-arrow-right"></i><a href="{{ route('abonnements.page') }}"
                                                                           class="text-decoration-none">Tarifs</a></li>
                            <li><i class="fa-solid fa-arrow-right"></i><a href="{{ route('politique_de_confidentialite.page') }}"
                                                                          class="text-decoration-none">Politique de confidentialité</a></li>
                            <li><i class="fa-solid fa-arrow-right"></i><a href="{{ route('cgu.page') }}"
                                                                          class="text-decoration-none">Conditions générales d'utilisation</a></li>
                            <li><i class="fa-solid fa-arrow-right"></i><a href="{{ route('ml.page') }}"
                                                                          class="text-decoration-none">Mentions légales</a></li>
                        </ul>
                    </div>
                </div>
                <div class="col-xl-3 col-lg-3 col-md-4 col-sm-6 col-7">
                    <div class="icon">
                        <h4 class="heading">Contact</h4>
                        <ul class="list-unstyled mb-0">
                            <li class="text">
                                <i class="fa-solid fa-phone-volume"></i>
                                <a href="tel:+33652233359" class="text-decoration-none">+33 652 233 359</a>
                            </li>
                            <li class="text">
                                <i class="fa-solid fa-phone-volume"></i>
                                <a href="tel:+212633628578" class="text-decoration-none">+212 633 628 578</a>
                            </li>
                            <li class="text">
                                <i class="fa-solid fa-envelope"></i>
                                <a href="mailto:contact@elchat.io" class="text-decoration-none">contact@elchat.io</a>
                            </li>
                            <li class="text">
                                <i class="fa-solid fa-location-dot"></i>
                                <a href="https://www.google.com/maps/place/Casablanca/@33.5721783,-7.7518024,11z/data=!3m1!4b1!4m6!3m5!1s0xda7cd4778aa113b:0xb06c1d84f310fd3!8m2!3d33.5731104!4d-7.5898434!16zL20vMDIyYl8?entry=ttu&g_ep=EgoyMDI2MDYxMy4wIKXMDSoASAFQAw%3D%3D"
                                   class="text-decoration-none address mb-0">Casablanca, Maroc
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>
                <div class="col-xl-4 col-lg-4 col-md-5 col-sm-12 col-12">
                    <div class="email-form">
                        <h4 class="heading">Actualités ELChat</h4>
                        <form action="javascript:;">
                            <div class="form-group position-relative mb-0">
                                <input type="text" class="form_style" placeholder="Votre adresse e-mail professionnelle"
                                       name="email">
                                <button><i class="send fa-sharp fa-solid fa-paper-plane"></i></button>
                            </div>
                            <div class="form-group check-box mb-0">
                                <input type="checkbox" id="term">
                                <label for="term">J'accepte la <a href="{{ route('politique_de_confidentialite.page') }}">Politique de confidentialité</a>.</label>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <div class="copyright">
            <p class="mb-0">Copyright © 2026 ELChat. Tous droits réservés.
                Une solution développée par ELONGA ONASAMBI Josué et exploitée par BOSOLO TECH.
            </p>
        </div>
    </div>
</section>

<!-- PRE LOADER -->
<div class="loader-mask">
    <div class="loader">
        <div></div>
        <div></div>
    </div>
</div>
@section('scripts')
    <!-- Latest compiled JavaScript -->
    <script src="{{ asset('assets/js/jquery-3.7.1.min.js')}}"></script>
    <script src="{{ asset('assets/js/popper.min.js')}}"></script>
    <script src="{{ asset('assets/js/bootstrap.min.js')}}"></script>
    <script src="{{ asset('assets/js/owl.carousel.js')}}"></script>
    <script src="{{ asset('assets/js/carousel.js')}}"></script>
    <script src="{{ asset('assets/js/wow.js')}}"></script>
    <script src="{{ asset('assets/js/back-to-top-button.js')}}"></script>
    <script src="{{ asset('assets/js/preloader.js')}}"></script>
    <script src="{{ asset('assets/js/counter.js')}}"></script>
@show
</body>

</html>
