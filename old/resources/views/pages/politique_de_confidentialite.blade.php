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
                        <h1>Politique de confidentialité</h1>
                        <p>
                            Nous accordons une grande importance à la protection de votre vie privée et à la sécurité de vos données.
                            La présente Politique de confidentialité explique comment ELChat collecte, utilise, stocke,
                            et protège vos données personnelles lorsque vous utilisez notre plateforme.
                        </p>

                        <div class="breadcrumb-con d-inline-block">
                            <ol class="breadcrumb mb-0">
                                <li class="breadcrumb-item">
                                    <a href="{{ route('home.page') }}">Accueil</a>
                                </li>
                                <li class="breadcrumb-item active" aria-current="page">
                                    Politique de confidentialité
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

                    <h2>ELChat</h2>

                    <p>
                        Dernière mise à jour : 16/06/2026
                    </p>

                    <p>
                        La présente Politique de Confidentialité a pour objet d'informer les utilisateurs de la
                        plateforme ELChat des modalités de collecte, de traitement, de conservation et de
                        protection de leurs données à caractère personnel. <br>
                        ELChat accorde une importance particulière à la protection de la vie privée et s'engage à
                        traiter les données personnelles dans le respect de la loi marocaine n° 09-08 relative à la
                        protection des personnes physiques à l'égard du traitement des données à caractère
                        personnel ainsi que, lorsque applicable, du Règlement Général sur la Protection des Données
                        (RGPD) de l'Union européenne.
                    </p>

                    <h4>1. Responsable du traitement</h4>

                    <p>
                        Les données personnelles collectées dans le cadre de l'utilisation de la Plateforme sont
                        traitées par :
                    </p>

                    <h5>ELChat</h5>
                    <ul>
                        <li>Adresse : Casablanca, Maroc</li>
                        <li>Courriel : <a href="mailto:elongajosue22@gmail.com">elongajosue22@gmail.com</a> </li>
                        <li>Téléphone <a href="tel:+212633628578">:+212 633-628578</a> </li>
                    </ul>

                    <p>
                        La plateforme ELChat est exploitée par <strong>BOSOLO TECH</strong>.

                        Certaines opérations relatives à la fourniture du service, à la gestion des comptes clients, au support et aux intégrations avec des services tiers peuvent être réalisées par <strong>BOSOLO TECH</strong> .
                    </p>

                    <h4>2. Données collectées </h4>

                    <p>
                        Dans le cadre de l'utilisation des Services, ELChat est susceptible de collecter les catégories
                        de données suivantes :
                    </p>
                    <h5>Données d'identification</h5>
                    <ul>
                        <li>nom et prénom ;</li>
                        <li>nom de société ;</li>
                        <li>adresse électronique ;</li>
                        <li>numéro de téléphone ;</li>
                    </ul>

                    <h5>Données relatives au compte</h5>
                    <ul>
                        <li>identifiant utilisateur ;</li>
                        <li>mot de passe crypté ;</li>
                        <li>historique des connexions ;</li>
                        <li>préférences du compte.</li>
                    </ul>

                    <h5>Données de paiement</h5>
                    <p>
                        Pour les services payants :
                    </p>
                    <ul>
                        <li>informations relatives à l'abonnement ;</li>
                        <li>historique de facturation ;</li>
                        <li>données nécessaires au traitement des paiements.</li>
                    </ul>
                    <p>
                        Les informations bancaires complètes ne sont jamais conservées par ELChat lorsqu'elles sont
                        traitées par un prestataire de paiement sécurisé.
                    </p>

                    <h5>Données techniques</h5>

                    <ul>
                        <li>adresse IP ;</li>
                        <li>type de navigateur ;</li>
                        <li>système d'exploitation ;</li>
                        <li>langue utilisée ;</li>
                        <li>données de connexion ;</li>
                        <li>journaux techniques.</li>
                    </ul>

                    <h4>Données issues des Services</h4>

                    <p>
                        Lorsque l'Utilisateur connecte des services tiers à ELChat :
                    </p>

                    <ul>
                        <li>commentaires ;</li>
                        <li>messages ;</li>
                        <li>publications ;</li>
                        <li>métadonnées associées ;</li>
                        <li>statistiques d'utilisation.</li>
                    </ul>

                    <h4>Données générées par l'intelligence artificielle</h4>
                    <ul>
                        <li>Prompts</li>
                        <li>Instructions personnalisées</li>
                        <li>Réponses générées</li>
                        <li>Historique des conversations</li>
                        <li>Paramètres d'automatisation</li>
                    </ul>

                    <h4>Données des réseaux sociaux connectés</h4>
                    <p>
                        Selon les autorisations accordées :
                    </p>
                    <h4>YouTube</h4>
                    <ul>
                        <li>Identifiant de chaîne</li>
                        <li>Nom de chaîne</li>
                        <li>Commentaires</li>
                        <li>Réponses aux commentaires</li>
                        <li>Métadonnées associées</li>
                    </ul>

                    <h4>Instagram</h4>
                    <ul>
                        <li>Identifiant Instagram</li>
                        <li>Nom du compte</li>
                        <li>Messages reçus</li>
                        <li>HCommentaires</li>
                    </ul>

                    <h4>Facebook</h4>
                    <ul>
                        <li>Pages connectées
                        </li>
                        <li>Messages</li>
                        <li>Commentaires</li>
                        <li>Publications</li>
                    </ul>

                    <h4>WhatsApp</h4>
                    <ul>
                        <li>Messages reçus</li>
                        <li>Messages envoyés</li>
                        <li>Métadonnées de conversation</li>
                    </ul>

                    <h4>Autres plateformes</h4>
                    <p>
                        Même logique :
                    </p>
                    <ul>
                        <li>Identifiants</li>
                        <li>Messages</li>
                        <li>Interactions</li>
                        <li>Métadonnées</li>
                    </ul>

                    <h4>3. Finalités des traitements</h4>

                    <p>
                        Les données personnelles sont collectées pour :
                    </p>
                    <ul>
                        <li>créer et administrer les comptes utilisateurs ;</li>
                        <li>fournir les Services proposés par ELChat ;</li>
                        <li>gérer les abonnements et paiements ;</li>
                        <li>assurer le support client ;</li>
                        <li>améliorer les performances de la Plateforme ;</li>
                        <li>assurer la sécurité des Services ;</li>
                        <li>prévenir les fraudes et utilisations abusives ;
                        </li>
                        <li>répondre aux obligations légales et réglementaires;</li>
                        <li>établir des statistiques anonymisées ;</li>
                        <li>communiquer avec les utilisateurs concernant leurs comptes ou les Services.</li>
                    </ul>

                    <h4>4. Bases légales du traitement</h4>

                    <p>
                        Les traitements réalisés par ELChat reposent sur :
                    </p>

                    <h5>L'exécution du contrat</h5>
                    <p>
                        Lorsque le traitement est nécessaire à la fourniture des Services.
                    </p>
                    <h5>Le consentement</h5>
                    <p>
                        Lorsque la réglementation exige le consentement préalable de l'Utilisateur.
                    </p>
                    <h5>L'intérêt légitime</h5>
                    <p>
                        Notamment pour :
                    </p>

                    <ul>
                        <li>la sécurisation de la Plateforme ;</li>
                        <li>la prévention de la fraude ;</li>
                        <li>l'amélioration des Services.</li>
                    </ul>

                    <h5>L'obligation légale</h5>
                    <p>
                        Lorsque le traitement est imposé par une disposition légale ou réglementaire.
                    </p>


                    <h4>5. Destinataires des données</h4>

                    <p>
                        Les données peuvent être accessibles :
                    </p>

                    <ul>
                        <li>aux collaborateurs habilités de l'Éditeur ;</li>
                        <li>aux prestataires techniques ;</li>
                        <li>aux fournisseurs de services cloud ;</li>
                        <li>aux prestataires de paiement ;</li>
                        <li>aux fournisseurs de services d'intelligence artificielle ;</li>
                        <li>aux autorités administratives ou judiciaires lorsque la loi l'exige.</li>
                    </ul>

                    <p>
                        Tous les destinataires sont soumis à des obligations de confidentialité appropriées.
                    </p>

                    <h4>6. Utilisation de fournisseurs d'intelligence artificielle</h4>

                    <p>
                        Pour assurer certaines fonctionnalités, ELChat peut recourir à des fournisseurs tiers
                        d'intelligence artificielle.
                    </p>

                    <p>
                        À cette fin, certaines informations transmises par l'Utilisateur peuvent être envoyées aux
                        modèles d'intelligence artificielle utilisés par ELChat afin de générer les réponses ou analyses
                        sollicitées.

                    </p>

                    <p>
                        L'Éditeur veille à sélectionner des prestataires présentant des garanties appropriées en
                        matière de sécurité et de protection des données.
                    </p>

                    <p>
                        L'Utilisateur est invité à ne pas transmettre, via les Services, des données sensibles,
                        confidentielles ou protégées lorsqu'elles ne sont pas strictement nécessaires à l'utilisation du
                        Service.
                    </p>

                    <h4>7. Transfert international des données</h4>

                    <p>
                        Les données personnelles peuvent être transférées vers des pays situés en dehors du
                        Royaume du Maroc ou de l'Union européenne.
                    </p>

                    <p>
                        Dans cette hypothèse, ELChat met en œuvre les garanties appropriées prévues par la
                        réglementation applicable, notamment :
                    </p>

                    <ul>
                        <li>clauses contractuelles types ;</li>
                        <li>mesures de sécurité complémentaires ;</li>
                        <li>garanties contractuelles imposées aux sous-traitants.</li>
                    </ul>

                    <h4>8. Durée de conservation</h4>

                    <p>
                        Les données personnelles sont conservées uniquement pendant la durée nécessaire aux finalités poursuivies.
                        À titre indicatif :
                    </p>

                    <table style="margin-bottom: 1rem;">
                        <thead>
                            <tr>
                                <th>Catégorie</th>
                                <th>Durée</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Compte utilisateur</td>
                                <td>Pendant toute la durée du compte</td>
                            </tr>
                            <tr>
                                <td>Données de facturation</td>
                                <td>10 ans</td>
                            </tr>
                            <tr>
                                <td>Journaux techniques</td>
                                <td>12 mois</td>
                            </tr>
                            <tr>
                                <td>Demandes de support</td>
                                <td>3 ans</td>
                            </tr>
                            <tr>
                                <td>Prospection commerciale</td>
                                <td>3 ans après le dernier contact</td>
                            </tr>
                            <tr>
                                <td>Paiement</td>
                                <td>12 mois</td>
                            </tr>
                        </tbody>
                    </table>

                    <p>
                        À l'issue des périodes applicables, les données sont supprimées ou anonymisées.
                    </p>

                    <h4>9. Sécurité des données</h4>

                    <p>
                        ELChat met en oeuvre des mesures techniques et organisationnelles appropriées visant à garantir :
                    </p>

                    <ul>
                        <li>la confidentialité ;</li>
                        <li>l'intégrité ;</li>
                        <li>la disponibilité ;</li>
                        <li>la résilience des systèmes.</li>
                    </ul>

                    <p>
                        Ces mesures comprennent notamment :
                    </p>

                    <ul>
                        <li>chiffrement des communications ;</li>
                        <li>authentification sécurisée ;</li>
                        <li>limitation des accès ;</li>
                        <li>surveillance des systèmes ;</li>
                        <li>sauvegardes régulières.</li>
                    </ul>

                    <p>
                        Malgré ces mesures, aucun système de transmission ou de stockage électronique ne peut garantir une sécurité absolue.
                    </p>

                    <h4>10. Cookies et traceurs</h4>

                    <p>
                        La Plateforme utilise des cookies et technologies similaires afin :
                    </p>

                    <ul>
                        <li>d'assurer son fonctionnement ;</li>
                        <li>d'améliorer l'expérience utilisateur ;</li>
                        <li>d'établir des statistiques ;</li>
                        <li>de sécuriser les Services.</li>
                    </ul>

                    <p>
                        L'Utilisateur peut configurer son navigateur afin de refuser tout ou partie des cookies. <br>
                        Une politique spécifique relative aux cookies peut être mise à disposition sur la Plateforme.
                    </p>

                    <h4>11. Droits des utilisateurs</h4>

                    <p>
                        Conformément à la réglementation applicable, l'Utilisateur dispose des droits suivants :
                    </p>

                    <ul>
                        <li>droit d'accès ;</li>
                        <li>droit de rectification ;</li>
                        <li>droit d'effacement ;</li>
                        <li>droit d'opposition ;</li>
                        <li>droit à la limitation du traitement ;</li>
                        <li>droit à la portabilité des données ;</li>
                        <li>droit de retirer son consentement à tout moment lorsque le traitement repose sur celui-ci.</li>
                    </ul>

                    <p>
                        Toute demande peut être adressée à :<a href="mailto:contact@elchat.io">contact@elchat.io</a> <br>

                        L'Éditeur pourra demander un justificatif d'identité lorsque cela est nécessaire.
                    </p>

                    <h4>12. Données des mineurs</h4>

                    <p>
                        Conformément aux CGU, l'accès à la Plateforme est réservé aux personnes âgées d'au moins treize (13) ans ou de l'âge minimum requis par la législation applicable dans leur pays de résidence.
                    </p>
                    <p>
                        Lorsque le consentement parental est requis par la loi applicable, l'Utilisateur déclare avoir obtenu l'autorisation nécessaire avant toute utilisation des Services.
                    </p>

                    <h4>13. Réclamations</h4>

                    <p>
                        L'Utilisateur dispose du droit d'introduire une réclamation auprès de l'autorité compétente en matière de protection des données.
                    </p>
                    <p>Pour le Royaume du Maroc, il s'agit de la :</p>
                    <p>Commission Nationale de Contrôle de la Protection des Données à Caractère Personnel</p>
                    <p>Site officiel : <a href="mailto:https://www.cndp.ma/">CNDP Maroc</a> </p>

                    <h4>14. Modification de la Politique de Confidentialité</h4>

                    <p>
                        L'Éditeur se réserve le droit de modifier à tout moment la présente Politique de Confidentialité afin de tenir compte des évolutions légales, réglementaires, techniques ou opérationnelles.
                    </p>
                    <p>
                        La version applicable est celle publiée sur la Plateforme à la date de consultation par l'Utilisateur.
                    </p>

                    <h4>15. Contact</h4>

                    <p class="mb-0">
                        Pour toute question relative à la présente Politique de Confidentialité ou au traitement de vos données personnelles, vous pouvez contacter :
                    </p>

                    <p class="mb-0">
                        <strong>ELChat</strong><br>
                        Email: <a href="mailto:contact@elchat.io">contact@elchat.io</a> <br>
                        Téléphone: <a href="tel:+212633628578">+212 633-628578</a> <br>
                        Site Web: <a href="https://elchat.io">https://elchat.io</a> <br>
                    </p>

                </div>
            </div>

        </div>
    </section>

@endsection
