@extends('pages.layouts.blank')

@section('seo')
    <meta name="robots" content="noindex,follow">
@endsection

@section('main-content')
    <!-- SUB BANNER SECTION -->
    <section class="float-left w-100 sub-banner-con position-relative main-box">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-7 col-md-7">
                    <div class="sub-banner-content-con">
                        <h1>Mentions légales</h1>
                        <p>
                            Les présentes mentions légales ont pour objet d'informer les utilisateurs
                            de l'identité de l'éditeur d'ELChat, de l'hébergeur du site, ainsi que
                            des conditions légales applicables à l'accès et à l'utilisation de la
                            plateforme.
                        </p>

                        <div class="breadcrumb-con d-inline-block">
                            <ol class="breadcrumb mb-0">
                                <li class="breadcrumb-item">
                                    <a href="{{ route('home.page') }}">Accueil</a>
                                </li>
                                <li class="breadcrumb-item active" aria-current="page">
                                    Mentions légales
                                </li>
                            </ol>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5 col-md-5">
                    <div class="sub-banner-img-con">
                        <figure>
                            <img src="{{ asset('assets/images/sub-banner-img.png')}}" alt="Illustration ELChat">
                        </figure>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- PRIVACY POLICY CONTENT SECTION -->
    <section class="float-left w-100 privacy-policy-content-con position-relative padding-top main-box">
        <div class="container">

            <div class="row">
                <div class="col-12">

                    <p>
                        Dernière mise à jour : 16/06/2026
                    </p>

                    <h4>1. Éditeur du Site</h4>

                    <p>
                        Le présent site internet accessible à l'adresse <a href="https://elchat.io/">https://elchat.io/</a> est édité par:
                    </p>
                    <ul>
                        <li>ELONGA ONASAMBI Josué</li>
                        <li>Email : <a href="mailto:elongajosue22@gmail.com">elongajosue22@gmail.com</a> </li>
                        <li>Téléphone <a href="tel:+212633628578">:+212 633-628578</a> </li>
                    </ul>
                    <p>
                        La plateforme ELChat est exploitée et commercialisée par <strong>BOSOLO TECH</strong> dans le cadre de ses activités professionnelles.
                    </p>

                    <h4>2. Hébergement</h4>

                    <p>
                        Le Site est hébergé par :
                    </p>
                    <p>
                        <strong>Hostinger</strong><br>
                        Adresse : Jonavos g. 60C, LT-44192, Kaunas, Lituanie
                        Email: <a href="mailto:support@hostinger.com">support@hostinger.com</a> <br>
                        Site Web: <a href="https://www.hostinger.com">https://www.hostinger.com</a> <br>
                    </p>
                    <p>
                        L'hébergeur assure le stockage des données et le maintien de l'infrastructure technique nécessaire au fonctionnement de la Plateforme.
                    </p>
                    <h4>3. Présentation du Service</h4>
                    <p>
                        ELChat est une plateforme logicielle accessible en mode SaaS (Software as a Service) permettant notamment :
                    </p>

                    <ul>
                        <li>l'automatisation des réponses aux commentaires et messages ;</li>
                        <li>l'assistance conversationnelle par intelligence artificielle ;</li>
                        <li>la gestion centralisée des interactions numériques ;</li>
                        <li>l'analyse de contenus et conversations ;</li>
                        <li>l'amélioration de l'engagement client ;</li>
                        <li>l'intégration avec certains services tiers et réseaux sociaux.</li>
                    </ul>
                    <p>Les fonctionnalités proposées sont susceptibles d'évoluer à tout moment afin d'améliorer la qualité des Services.</p>

                    <h4>4. Propriété Intellectuelle</h4>

                    <p>
                        L'ensemble des éléments composant la Plateforme, notamment :
                    </p>


                    <ul>
                        <li>la marque ELChat ;</li>
                        <li>les logos ;</li>
                        <li>les textes ;</li>
                        <li>les interfaces graphiques ;</li>
                        <li>les bases de données ;</li>
                        <li>les logiciels ;</li>
                        <li>les développements informatiques ;</li>
                        <li>les éléments visuels et sonores ;</li>
                    </ul>

                    <p>
                        sont protégés par les dispositions relatives à la propriété intellectuelle et demeurent la propriété exclusive de l'Éditeur ou de ses partenaires.
                    </p>

                    <p>
                        Toute reproduction, représentation, adaptation, modification, extraction ou exploitation, totale ou partielle, sans autorisation écrite préalable de l'Éditeur est strictement interdite.
                    </p>

                    <h4>5. Données Personnelles</h4>
                    <p>
                        L'Éditeur collecte et traite certaines données à caractère personnel dans le cadre de l'exploitation de la Plateforme.
                    </p>
                    <p>
                        Les modalités de collecte, de traitement, de conservation et de protection des données personnelles sont détaillées dans la Politique de Confidentialité accessible sur le Site.
                    </p>
                    <p>
                        L'Utilisateur dispose des droits prévus par la réglementation applicable, notamment des droits d'accès, de rectification, d'opposition et, lorsque applicable, de suppression de ses données personnelles.
                    </p>

                    <h4>6. Cookies</h4>
                    <p>
                        Le Site peut utiliser des cookies et technologies similaires afin :
                    </p>
                    <ul>
                        <li>d'assurer son bon fonctionnement ;</li>
                        <li>d'améliorer l'expérience utilisateur ;</li>
                        <li>d'effectuer des mesures d'audience ;</li>
                        <li>de renforcer la sécurité des Services.</li>
                    </ul>

                    <p>
                        L'Utilisateur peut gérer ses préférences relatives aux cookies à partir des paramètres de son navigateur ou du gestionnaire de consentement mis à sa disposition sur le Site.
                    </p>

                    <h4>7. Responsabilité</h4>
                    <p>
                        L'Éditeur met en oeuvre tous les moyens raisonnables afin d'assurer l'exactitude des informations diffusées sur la Plateforme.
                    </p>
                    <p>
                        Toutefois, l'Éditeur ne saurait garantir l'absence totale d'erreurs, d'interruptions ou d'indisponibilités temporaires.
                    </p>
                    <p>
                        L'Utilisateur demeure seul responsable de l'utilisation qu'il fait des Services, des contenus qu'il publie ainsi que des décisions prises sur la base des informations générées par les outils d'intelligence artificielle.
                    </p>

                    <h4>8. Liens Hypertextes</h4>
                    <p>
                        La Plateforme peut contenir des liens vers des sites internet ou services exploités par des tiers.
                    </p>
                    <p>
                        L'Éditeur n'exerce aucun contrôle sur ces sites et décline toute responsabilité quant à leur contenu, leur disponibilité ou leurs pratiques en matière de protection des données personnelles.
                    </p>

                    <h4>9. Droit Applicable</h4>
                    <p>
                        Les présentes Mentions Légales sont régies par le droit marocain.
                    </p>
                    <p>
                        Tout litige relatif à leur interprétation ou à leur exécution relèvera de la compétence exclusive des tribunaux de Casablanca, sous réserve des dispositions légales impératives applicables.
                    </p>

                    <h4>10. Contact</h4>
                    <p>
                        Pour toute question relative au Site, aux Services ou aux présentes Mentions Légales, l'Utilisateur peut contacter l'Éditeur :
                    </p>
                    <p class="mb-0">
                        <strong>ELChat</strong><br>
                        Adresse : Casablanca , Maroc
                        Email: <a href="mailto:contact@elchat.io">contact@elchat.io</a> <br>
                        Téléphone: <a href="tel:+212633628578">+212 633-628578</a> <br>
                        Site Web: <a href="https://elchat.io">https://elchat.io</a> <br>
                    </p>
                </div>
            </div>

        </div>
    </section>
@endsection
