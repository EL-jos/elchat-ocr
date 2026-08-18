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
                        <h1>Conditions générales d’utilisation</h1>
                        <p>
                            En accédant à ELChat et en utilisant ses services, vous acceptez
                            de respecter les présentes Conditions générales d'utilisation.
                            Ces conditions définissent les règles applicables à l'utilisation
                            de la plateforme, ainsi que les droits et obligations des utilisateurs.
                        </p>

                        <div class="breadcrumb-con d-inline-block">
                            <ol class="breadcrumb mb-0">
                                <li class="breadcrumb-item">
                                    <a href="{{ route('home.page') }}">Accueil</a>
                                </li>
                                <li class="breadcrumb-item active" aria-current="page">
                                    Conditions générales d'utilisation
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

                    <p>
                        Les présentes Conditions Générales d’Utilisation (dites “ CGU ” ) ont pour objet la détermination du fonctionnement et
                        l’encadrement juridique de l’ assistant conversation IA ci-après dénommé (“ ELChat”) ,
                        accessible via l’url - <a href="https://elchat.io/">https://elchat.io/</a> - ainsi que ses modalités d’utilisations par les (“Utilisateurs”) ou (“l’Utilisateur”).
                    </p>
                    <p>
                        Les présentes CGU constituent un contrat d’adhésion pour les utilisateurs et doivent être lues attentivement et acceptées avant toute utilisation.
                    </p>
                    <p>
                        L’acceptation des présentes constitue une condition d’accès à ELChat sans laquelle aucune utilisation n’est possible.
                    </p>

                    <h4>1. Définitions</h4>

                    <p>
                        Aux fins des présentes Conditions Générales d'Utilisation, les termes ci-après auront la signification suivante :
                    </p>
                    <p>
                        <strong>« ELChat » ou « Plateforme »</strong> : désigne la plateforme numérique accessible à l'adresse https://elchat.io/accueil, ainsi que l'ensemble des fonctionnalités, services, logiciels, interfaces, contenus et technologies qui y sont associés.
                    </p>
                    <p>
                        <strong>« Éditeur » “Fournisseur” “Exploitant”</strong> : désigne la société propriétaire et éditrice de la Plateforme ELChat, assurant son développement, son exploitation, sa maintenance et sa commercialisation.
                    </p>
                    <p>
                       <strong> « Utilisateur »</strong> : désigne toute personne physique ou morale accédant à la Plateforme ou utilisant les Services proposés par ELChat.
                    </p>
                    <p>
                        <strong>« Compte »</strong> : désigne l'espace personnel créé par l'Utilisateur lui permettant d'accéder aux fonctionnalités de la Plateforme.
                    </p>
                    <p>
                        <strong>« Services »</strong> : désignent l'ensemble des fonctionnalités mises à disposition par ELChat, notamment l'automatisation des interactions numériques, la génération de réponses assistées par intelligence artificielle, l'analyse de contenus, la gestion des interactions clients ainsi que toute autre fonctionnalité proposée sur la Plateforme.
                    </p>
                    <p>
                        <strong>« Contenu »</strong> : désigne toute donnée, information, texte, image, document, message, publication, commentaire ou élément transmis, publié, traité ou généré via la Plateforme.
                    </p>
                    <p>
                        <strong>« Services Tiers »</strong> : désignent les services, logiciels, applications ou plateformes externes utilisés conjointement avec ELChat, notamment les réseaux sociaux, services cloud, fournisseurs d'intelligence artificielle, applications de messagerie ou toute autre solution partenaire.
                    </p>
                    <p>
                        <strong>« Intuitu Personae »</strong> : désigne le caractère personnel de la relation contractuelle liant l'Utilisateur à l'Éditeur. Le Compte étant créé en considération de la personne de l'Utilisateur, celui-ci est strictement personnel et ne peut être cédé, prêté, partagé ou transféré à un tiers sans l'autorisation préalable et écrite de l'Éditeur.
                    </p>

                    <h5>2. Objet du service</h5>
                    <p>
                        ELChat est une plateforme logicielle SaaS (Software as a Service) accessible en ligne, permettant l'automatisation, l'analyse et la gestion des interactions numériques grâce à des technologies d'intelligence artificielle.
                    </p>
                    <p>
                        La plateforme peut notamment être connectée à différents services tiers tels que les réseaux sociaux, applications de messagerie et outils numériques compatibles afin de traiter les interactions des utilisateurs et générer des réponses automatisées.
                    </p>
                    <p>
                        La solution permet notamment :
                    </p>
                    <ul>
                        <li>l'automatisation des réponses aux commentaires sur les réseaux sociaux ;</li>
                        <li>l'automatisation des réponses aux messages privés ;</li>
                        <li>la gestion centralisée des interactions clients ;</li>
                        <li>l'amélioration de l'engagement communautaire ;</li>
                        <li>la génération de réponses assistées par intelligence artificielle ;</li>
                        <li>l'analyse des contenus et des conversations ;</li>
                        <li>la collecte et l'exploitation d'informations issues des interactions numériques ;</li>
                    </ul>

                    <h4>3. Inscription et accès </h4>

                    <p>
                        Âge minimum: Conformément aux standards internationaux en la matière, l’âge minimum pour accéder à la Plate-forme est de 13 ans ou l’âge requis selon le pays dans lequel vous vous trouvez.
                        <br>
                        Inscription: Pour créer un compte utilisateur, vous devez fournir les éléments qui vous seront exigés au moment de la création afin d’accéder à nos Services. L’utilisateur accède à ELChat via un navigateur Internet et rentre le site web d'ELChat ; aucune installation locale n'est nécessaire ;
                    </p>

                    <p>
                        Il est également rappelé que la création d’un compte sur notre plateforme relève de l’Intuitu Personae ( voir définitions) et le partage des identifiants d’un compte ou la mise à disposition de celui-ci à un tiers autre l’Utilisateur est interdit. Par ailleurs , en raison de ce même caractère intuitu personae, l’Utilisateur est responsable de toutes les activités du compte.
                    </p>

                    <h4>4. Utilisation du service</h4>
                    <p>
                        <strong>Compte:</strong> L’utilisation de la Plateforme est conditionnée par l’acceptation des présentes et la création d’un compte utilisateur soit gratuit mais limité, soit payant avec des fonctionnalités plus attrayantes.L’accès à un compte payant se caractérise par la souscription à un abonnement soit annuel soit mensuel selon les différentes formules disponibles.
                    </p>
                    <p>
                        ELChat étant un assistant conversation, son usage par l’Utilisateur doit être conforme à la législation en vigueur dans son pays , notamment en ce qui concerne la protection des données personnelles.
                    </p>
                    <p>
                        IL est notamment interdit à l’Utilisateur, dans le cadre de l’utilisation de l'assistant, de :
                    </p>
                    <ul>
                        <li>utiliser ELChat à des fins frauduleuses ;</li>
                        <li>diffuser des contenus illégaux ;</li>
                        <li>harceler, menacer ou discriminer des tiers ;</li>
                        <li>usurper l'identité d'une personne ou d'une organisation ;</li>
                        <li>contourner les mécanismes de sécurité ;</li>
                        <li>revendre ou sous-licencier le service sans autorisation ;</li>
                        <li>utiliser ELChat pour générer du spam massif ;</li>
                        <li>tenter d'accéder aux données d'autres utilisateurs ;</li>
                        <li>utiliser le service pour diffuser des logiciels malveillants.</li>
                        <li>Utiliser le nom ou le Logo de ELChat pour ses projets personnels et sans autorisations</li>
                    </ul>

                    <p>
                        <strong>Services tiers</strong> : Certaines fonctionnalités reposent sur des services tiers tels que les réseaux sociaux, fournisseurs d'intelligence artificielle, services cloud ou plateformes partenaires.
                        ELChat ne saurait être tenu responsable d'une interruption ou modification de ces services tiers.
                    </p>

                    <h4>5. Obligations de l’Utilisateur</h4>
                    <p>
                        Le compte utilisateur est strictement personnel.
                        L'utilisateur est responsable de la confidentialité de ses identifiants.
                        Le partage non autorisé des accès est interdit sauf disposition contraire prévue dans une offre professionnelle multi-utilisateurs.
                    </p>
                    <p>
                        L'utilisateur s'engage à respecter l'ensemble des lois et réglementations applicables dans son pays de résidence ainsi que dans les pays où ses activités sont exercées.
                    </p>

                    <h4>6. Données personnelles</h4>

                    <h5>6.1. Collecte des données personnelles</h5>

                    <p>
                        Dans le cadre de l'utilisation de la Plateforme, l'Utilisateur est susceptible de communiquer à ELChat certaines données à caractère personnel le concernant, notamment :
                    </p>

                    <ul>
                        <li>les données d'identification (nom, prénom, date de naissance) ;</li>
                        <li>les coordonnées de contact (adresse électronique, numéro de téléphone, adresse postale) ;</li>
                        <li>les informations relatives au compte utilisateur ;</li>
                        <li>les données de connexion et de navigation ;</li>
                        <li>toute autre donnée nécessaire à la fourniture des services proposés sur la Plateforme.</li>
                    </ul>

                    <p>
                        Les données collectées sont strictement limitées à celles nécessaires à la réalisation des finalités poursuivies.
                    </p>

                    <h5>6.2. Responsable du traitement</h5>
                    <p>
                        Les données à caractère personnel collectées sur la Plateforme sont traitées par <strong>ELChat</strong> agissant en qualité de
                        responsable du traitement au sens de la loi <strong>n° 09-08 relative à la protection des personnes physiques à l'égard du
                            traitement des données à caractère personnel ( Maroc)</strong> et, le cas échéant, du <strong>Règlement (UE) 2016/679 du 27 avril 2016 (RGPD)</strong>.
                    </p>
                    <p>
                        Pour toute question relative au traitement de ses données personnelles, l'Utilisateur peut contacter :
                    </p>
                    <ul>
                        <li>Email : <a href="mailto:contact@elchat.io">contact@elchat.io</a> </li>
                        <li>Téléphone <a href="tel:+212633628578">:+212 633-628578</a> </li>
                    </ul>

                    <h5>6.3. Finalités du traitement</h5>
                    <p>
                        Les données personnelles sont collectées et traitées pour les finalités suivantes :
                    </p>
                    <ul>
                        <li>la création et la gestion du compte utilisateur ;</li>
                        <li>l'accès aux fonctionnalités de la Plateforme ;</li>
                        <li>la gestion des commandes, abonnements ou prestations ;</li>
                        <li>l'assistance et le support utilisateur ;</li>
                        <li>la gestion de la relation client ;</li>
                        <li>l'amélioration des services et de l'expérience utilisateur ;</li>
                        <li>l'envoi de communications relatives au fonctionnement de la Plateforme ;</li>
                        <li>le respect des obligations légales et réglementaires applicables ;</li>
                        <li>la prévention de la fraude, des abus et des atteintes à la sécurité de la Plateforme.</li>
                    </ul>

                    <h5>6.4. Base juridique du traitement</h5>
                    <p>
                        Les traitements de données personnelles reposent, selon les cas, sur :
                    </p>
                    <ul>
                        <li>l'exécution d'un contrat auquel l'Utilisateur est partie ;</li>
                        <li>le consentement de l'Utilisateur ;</li>
                        <li>le respect d'une obligation légale ;</li>
                        <li>l'intérêt légitime poursuivi par ELChat , sous réserve du respect des droits et libertés fondamentaux de l'Utilisateur.</li>
                    </ul>

                    <h5>6.5. Destinataires des données</h5>
                    <p>
                        Les données personnelles sont accessibles uniquement :
                    </p>
                    <ul>
                        <li>aux personnels habilités de ELChat ;</li>
                        <li>aux sous-traitants intervenant dans le cadre de la fourniture des services (hébergement, maintenance, paiement, assistance technique, etc.) ;</li>
                        <li>aux autorités administratives ou judiciaires lorsque la loi l'exige.</li>
                    </ul>
                    <p>
                        Les sous-traitants sont tenus à des obligations strictes de confidentialité et de sécurité conformément à la réglementation applicable.
                    </p>

                    <h5>6.6. Transfert des données</h5>
                    <p>
                        Lorsque les données personnelles sont transférées vers un État n'assurant pas un niveau de protection adéquat, l'Éditeur met en oeuvre les garanties appropriées requises par la réglementation applicable, notamment les clauses contractuelles types approuvées par les autorités compétentes ou tout autre mécanisme légalement reconnu.
                    </p>

                    <h5>6.7. Durée de conservation</h5>
                    <p>
                        Les données personnelles sont conservées pendant une durée n'excédant pas celle nécessaire aux finalités pour lesquelles elles ont été collectées, augmentée, le cas échéant, des délais légaux de prescription applicables.
                    </p>
                    <p>
                        À l'expiration de ces délais, les données sont supprimées ou anonymisées conformément à la réglementation en vigueur.
                    </p>

                    <h5>6.8. Droits des utilisateurs</h5>

                    <p>
                        Conformément à la loi marocaine n° 09-08 et, le cas échéant, au RGPD, l'Utilisateur dispose des droits suivants :
                    </p>
                    <ul>
                        <li>droit d'accès à ses données personnelles ;</li>
                        <li>droit de rectification des données inexactes ou incomplètes ;</li>
                        <li>droit à l'effacement des données lorsque celui-ci est légalement applicable ;</li>
                        <li>droit à la limitation du traitement ;</li>
                        <li>droit d'opposition au traitement pour des motifs légitimes ;</li>
                        <li>droit à la portabilité des données lorsque ce droit est applicable ;</li>
                        <li>droit de retirer son consentement à tout moment lorsque le traitement est fondé sur celui-ci.</li>
                    </ul>

                    <p>
                        L'Utilisateur peut exercer ses droits en adressant sa demande à l'adresse suivante : <a href="mailto:contact@elchat.io">contact@elchat.io</a> .
                    </p>
                    <p>Une réponse lui sera apportée dans les délais prévus par la réglementation applicable.</p>

                    <h5>6.9. Sécurité des données</h5>

                    <p>
                        L'Éditeur met en oeuvre toutes les mesures techniques, organisationnelles et de sécurité appropriées afin de protéger les données personnelles contre toute destruction, perte, altération, divulgation ou accès non autorisé.
                    </p>

                    <p>
                        Toutefois, l'Utilisateur reconnaît qu'aucune transmission de données sur Internet ne peut être garantie comme totalement sécurisée.
                    </p>

                    <h5>6.10. Réclamation auprès de l'autorité compétente</h5>
                    <p>
                        Sans préjudice de tout autre recours administratif ou judiciaire, l'Utilisateur dispose du droit d'introduire une réclamation auprès de l'autorité compétente en matière de protection des données personnelles.
                    </p>
                    <p>
                        Pour le Maroc, il s'agit de la :
                    </p>
                    <p>
                        Commission Nationale de Contrôle de la Protection des Données à Caractère Personnel
                        et de son site officiel : <a href="mailto:https://www.cndp.ma/">CNDP Maroc</a>
                    </p>

                    <h4>7. Propriété intellectuelle</h4>
                    <p>
                        Sous réserve du respect des présentes conditions, l'utilisateur dispose d'un droit d'utilisation des contenus générés par ELChat dans le cadre de ses activités personnelles ou professionnelles.
                    </p>
                    <p>
                        ELChat ne revendique aucun droit de propriété sur les réponses générées spécifiquement pour l'utilisateur.
                    </p>
                    <p>
                        Nous et nos affiliés sommes titulaires de tous les droits, titres et intérêts relatifs aux Services. L’Utilisateur ne peut utiliser notre nom et notre logo dans n'importe quel de ses projets personnels et sans autorisation.
                    </p>

                    <h4>8. Responsabilité</h4>
                    <p>
                        L'utilisateur demeure seul responsable :
                    </p>

                    <ul>
                        <li>des contenus publiés via ELChat ;</li>
                        <li>des réponses générées ou validées par l'intelligence artificielle ;</li>
                        <li>des décisions prises sur la base des informations fournies par le service.</li>
                    </ul>

                    <p>
                        ELChat fournit une assistance automatisée mais ne garantit ni l'exactitude absolue ni l'absence d'erreurs dans les contenus générés.
                    </p>
                    <p>
                        L'éditeur ne garantit ni l'exactitude, ni l'exhaustivité, ni la pertinence des réponses générées par l'intelligence artificielle.
                    </p>
                    <p>
                        ELChat ne saurait être tenu responsable des pertes financières, pertes de clientèle, atteintes à la réputation ou dommages indirects résultant de l'utilisation des contenus générés par l'intelligence artificielle.
                    </p>

                    <h4>9. Qualité du service</h4>

                    <p>
                        L’Utilisateur déclare être informé que pour accéder à la plateforme et aux services proposés, il aura besoin d’un réseau internet de bonne qualité et ce à sa charge.Aussi, il lui appartient d’avoir des équipements ( smartphone, ordinateur etc.) de bonne qualité et fiables , protégés de toute sorte de virus ou tout autre logiciel malveillant.Ainsi, tout équipement qu’il connecte relève de sa responsabilité.
                    </p>

                    <p>
                        <strong>L’Editeur</strong> s’engage à assurer ,dans le cadre d’une obligation de moyens, la continuité et la qualité de l’exploitation du service. A cet effet, l’Editeur s'attèle à faire tous ses efforts afin d’assurer l’accès à la plateforme 24/24h et 7/7J , étant donné que la faisabilité de cet objectif pourrait être empêchée par les cas de force majeure, ainsi que la bonne qualité du réseau internet fourni par les opérateurs de télécommunication ou tout autre cas similaire.Par ailleurs, l’Editeur se réserve le droit de limiter ou suspendre l’accès au site et/ou aux différents services en cas de nécessité notamment pour des raisons de maintenance, de changement de serveur et autres cas assimilés.
                    </p>

                    <p>
                        L'Éditeur veille à sélectionner des prestataires présentant des garanties appropriées en
                        matière de sécurité et de protection des données.
                    </p>

                    <p>
                        ELChat se réserve le droit de modifier, améliorer ou supprimer certaines fonctionnalités à tout moment afin de faire évoluer la plateforme.
                    </p>

                    <h4>10. Suspension et Arrêt du service</h4>

                    <h5>Suspension du Compte Utilisateur</h5>
                    <p>
                        L'Éditeur se réserve le droit de suspendre temporairement ou définitivement tout Compte Utilisateur en cas :
                    </p>

                    <ul>
                        <li>de violation des présentes CGU ;</li>
                        <li>d'utilisation frauduleuse, abusive ou illicite de la Plateforme ;</li>
                        <li>de non-respect des lois et réglementations applicables ;</li>
                        <li>d'atteinte à la sécurité, à l'intégrité ou au bon fonctionnement de la Plateforme ;</li>
                        <li>de défaut de paiement des sommes dues au titre d'un abonnement ou d'un service payant.</li>
                    </ul>

                    <p>Sauf urgence, risque pour la sécurité de la Plateforme ou obligation légale, l'Éditeur pourra informer préalablement l'Utilisateur de la suspension envisagée et lui accorder un délai raisonnable afin de régulariser sa situation.</p>

                    <h5>Suspension Temporaire du Service</h5>

                    <p>
                        L'Éditeur se réserve le droit de suspendre temporairement, en tout ou partie, l'accès à la Plateforme ou à certains Services, notamment pour les besoins de maintenance, de mise à jour, de correction d'anomalies, de renforcement de la sécurité ou pour toute autre nécessité technique ou opérationnelle.
                    </p>
                    <p>Dans la mesure du possible, l'Éditeur informera préalablement les Utilisateurs de toute interruption programmée.</p>
                    <h5>Cessation ou Arrêt Définitif du Service</h5>
                    <p>L'Éditeur se réserve le droit de modifier, limiter, interrompre ou mettre définitivement fin à l'exploitation de tout ou partie de la Plateforme à tout moment, sous réserve du respect des dispositions légales et contractuelles applicables.</p>
                    <p>Sauf urgence, force majeure ou obligation légale, l'Éditeur s'efforcera de notifier les Utilisateurs dans un délai raisonnable avant toute cessation définitive du Service.</p>
                    <h5>Conséquences de la Cessation du Service</h5>
                    <p>
                        En cas de cessation définitive de la Plateforme :
                    </p>
                    <ul>
                        <li>les Comptes Utilisateurs pourront être désactivés ou supprimés ;</li>
                        <li>les données personnelles seront conservées, supprimées ou anonymisées conformément à la réglementation applicable et à la Politique de Confidentialité ;</li>
                        <li>l'Utilisateur pourra récupérer ses données lorsque cette fonctionnalité est techniquement disponible et compatible avec les obligations légales applicables.</li>
                    </ul>
                    <p>
                        L'Utilisateur reconnaît que l'accès à la Plateforme ne lui confère aucun droit acquis au maintien des Services. L'Éditeur demeure libre de faire évoluer, modifier, suspendre ou supprimer tout ou partie des fonctionnalités proposées.
                    </p>

                    <h4>11. Limitation de Responsabilité</h4>

                    <p>
                        Le Fournisseur décline toute responsabilité quant à la nature et la provenance des données et/ou Documents reçus ou transmis via le Service.
                    </p>

                    <p>
                        Le Fournisseur n'est en aucun cas responsable de la nature des données et/ou Documents qu'il héberge ni des informations communiquées par l'Utilisateur au public et/ou aux tiers. Le Fournisseur ne pourra voir sa responsabilité recherchée ni engagée du fait des activités ou des informations stockées à la demande d'un Utilisateur, s'il n'avait pas effectivement connaissance de leur caractère illicite ou de faits et circonstances faisant apparaître ce caractère ou si, dès le moment où il en a eu connaissance, il a agi promptement pour retirer ces informations ou en rendre l'accès impossible.
                    </p>

                    <p>
                        À cet égard, le Fournisseur se réserve le droit de retirer ou de suspendre l'accès à toute donnée et/ou Document à la suite de la réception d'une notification de la violation des présentes ou si elle a effectivement connaissance du caractère manifestement illicite de la donnée et/ou du Document. La responsabilité du Fournisseur ne pourra en aucun cas être recherchée en raison de ce retrait.
                    </p>

                    <p>
                        Le Fournisseur n'assume aucune responsabilité pour les dommages qui pourraient être causés au matériel informatique/smartphone des Utilisateurs.
                    </p>

                    <p>La responsabilité du Fournisseur ne saurait être engagée dans les cas suivants :</p>
                    <ul>
                        <li>en cas d'utilisation du Service à des fins autres que celles prévues par les CGU ; pour défaut d'exécution des Services du fait imprévisible et insurmontable d'un tiers ;</li>
                        <li>en cas de force majeure, telle que définie par la loi et la jurisprudence marocaine ; pour le contenu de tout site internet vers lequel des liens hypertextes renvoient ;</li>
                        <li>en cas de perte, du fait de l'Utilisateur, de données, de Documents ou d'informations stockées sur la plateforme hébergeant le Service, l'Utilisateur devant réaliser les sauvegardes nécessaires à la conservation de ses données et informations ;</li>
                        <li>en cas d'utilisation anormale, ou non conforme, ou d'une exploitation illicite du Service par tout Utilisateur, ou tout tiers;</li>
                        <li>en cas de non-conformité du Service aux besoins ou aux attentes spécifiques de l'Utilisateur.</li>
                    </ul>
                    <p>
                        Par ailleurs, le Fournisseur ne pourra être tenu pour responsable des retards ou impossibilités de remplir ses obligations contractuelles, en cas :
                    </p>
                    <ul>
                        <li>d'interruption de la connexion au Service en raison d'opérations de maintenance planifiées,</li>
                        <li>d'impossibilité momentanée d'accès au Service en raison de problèmes techniques indépendants de la volonté du Fournisseur.</li>
                    </ul>

                    <h4>12. Résolution de Litiges</h4>

                    <p>
                        Les présentes Conditions Générales d'Utilisation sont régies par le droit marocain.
                    </p>
                    <p>
                        En cas de différend relatif à la validité, l'interprétation, l'exécution ou la résiliation des présentes CGU, les parties s'engagent à rechercher préalablement une solution amiable.
                    </p>
                    <p>
                        À défaut de règlement amiable dans un délai de trente (30) jours à compter de la notification écrite du différend par l'une des parties, les tribunaux compétents de Casablanca seront seuls compétents pour connaître du litige, sauf disposition légale impérative contraire.
                    </p>

                    <h4>13. Contact</h4>

                    <p>
                        Pour toute question relative aux présentes Conditions Générales d'Utilisation, à la protection des données personnelles ou à l'utilisation des Services, l'Utilisateur peut contacter l'Éditeur aux coordonnées suivantes :
                    </p>

                    <p class="mb-0">
                        Email: <a href="mailto:contact@elchat.io">contact@elchat.io</a> <br>
                        Téléphone: <a href="tel:+212633628578">+212 633-628578</a> <br>
                        Site Web: <a href="https://elchat.io">https://elchat.io</a> <br>
                    </p>

                    <p>
                        Toute demande adressée à l'Éditeur fera l'objet d'un traitement dans un délai raisonnable.
                    </p>
                </div>
            </div>

        </div>
    </section>

@endsection
