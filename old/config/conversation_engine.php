<?php

return [

    // Score de départ (= ResponseDepth::Normal) avant application des signaux.
    'baseline_score' => 3.0,

    // Poids appliqué selon l'intent détecté par le QueryAnalyzer.
    'intent_weights' => [
        'information'   => 0.0,
        'pricing'       => -0.5,
        'comparison'    => 1.5,
        'navigation'    => -1.0,
        'transactional' => -0.5,
        'support'       => 0.5,
        'lead'          => -0.5,
        'booking'       => -1.0,
        'download'      => -1.0,
    ],

    // Indices explicites détectés dans le texte brut de la question (regex simples).
    // Poids positif = pousse vers plus de détail, négatif = pousse vers plus de concision.
    'explicit_cues' => [
        ['pattern' => '/\b(explique|expliquer|détaille|détailler|en détail)\b/ui', 'weight' => 1.5],
        ['pattern' => '/\b(étape par étape|pas à pas|tutoriel|comment faire)\b/ui', 'weight' => 1.5],
        ['pattern' => '/\b(compare|comparer|différence entre|vs\.?)\b/ui', 'weight' => 1.0],
        ['pattern' => '/\b(pourquoi)\b/ui', 'weight' => 0.5],
        ['pattern' => '/\b(résume|résumer|en bref|rapidement|vite fait)\b/ui', 'weight' => -1.5],
        ['pattern' => '/\b(en gros|grosso modo)\b/ui', 'weight' => -1.0],
    ],

    // Nudge additif par rôle (nom du AIRole tel que seedé). Ne remplace jamais
    // le prompt du rôle, s'ajoute uniquement au calcul de profondeur/pace.
    'role_modifiers' => [
        'Expert'            => ['weight' => 1.0,  'hint' => 'tu peux structurer ta réponse avec plus de précision technique si le sujet s’y prête'],
        'Professeur'        => ['weight' => 0.5,  'hint' => 'tu peux construire ta réponse de façon pédagogique et progressive'],
        'Journaliste'       => ['weight' => 0.5,  'hint' => 'tu peux replacer l’information dans un contexte plus large si cela aide'],
        'Commercial'        => ['weight' => -0.5, 'hint' => 'reste orienté vers la décision, évite les digressions'],
        'Concierge'         => ['weight' => -1.0, 'hint' => 'privilégie la fluidité et la rapidité de la réponse'],
        'Support'           => ['weight' => -0.5, 'hint' => 'priorise la résolution concrète avant tout développement'],
        'Customer Success'  => ['weight' => 0.0,  'hint' => null],
        'Conseiller'        => ['weight' => 0.0,  'hint' => null],
        'Recruteur'         => ['weight' => 0.0,  'hint' => null],
        'Neutre'            => ['weight' => 0.0,  'hint' => null],
    ],

    // Nudge additif par type de site (nom du TypeSite tel que seedé).
    // Couvre les 18 types définis dans TypeSiteSeeder — vérifié contre le
    // fichier réel, pas une supposition. Chaque poids/hint est dérivé du
    // PÉRIMÈTRE / OBJECTIF / COMPORTEMENT déjà écrits dans le prompt de ce
    // type, pour rester cohérent avec ce qui existe plutôt que d'ajouter une
    // logique parallèle. Ce sont des points de départ, pas des valeurs
    // définitives : à ajuster ici, sans toucher au code, si l'usage réel
    // montre qu'un type se comporte différemment.
    'site_type_modifiers' => [
        'Site vitrine'            => ['weight' => -0.5, 'hint' => 'aide à la découverte : va à l’essentiel puis laisse la place à une question de l’utilisateur'],
        'Site associatif'         => ['weight' => -0.3, 'hint' => 'reste humain et concret, valorise l’impact sans développer excessivement'],
        'Comparateur'             => ['weight' => 1.0,  'hint' => 'structure la comparaison plutôt que de tout lister d’un bloc'],
        'Documentation'           => ['weight' => 1.0,  'hint' => 'les utilisateurs de documentation apprécient des réponses complètes quand le sujet est technique'],
        'E-commerce'              => ['weight' => -1.0, 'hint' => 'va rapidement à l’essentiel pour aider la décision d’achat'],
        'Blog'                    => ['weight' => -0.5, 'hint' => 'commence par l’idée principale avant de développer'],
        'SaaS'                    => ['weight' => 0.5,  'hint' => 'relie l’information à un usage concret si pertinent'],
        'Marketplace'             => ['weight' => -0.5, 'hint' => 'clarifie le besoin avant de recommander une option précise'],
        'Portail institutionnel'  => ['weight' => 0.3,  'hint' => 'reste factuel et structuré, n’interprète pas au-delà du texte officiel'],
        'Site éducatif'           => ['weight' => 1.0,  'hint' => 'construis ta réponse de façon progressive, comme un enchaînement pédagogique'],
        'Forum / Communauté'      => ['weight' => 0.5,  'hint' => 'structure les différents points de vue sans les hiérarchiser'],
        'Site d’actualités'       => ['weight' => -0.5, 'hint' => 'reste factuel et concis, sans ajouter de contexte non fourni'],
        'Landing page'            => ['weight' => -1.0, 'hint' => 'clarifie la proposition de valeur sans l’amplifier'],
        'Portfolio'               => ['weight' => -0.5, 'hint' => 'reste factuel et descriptif, sans superlatif'],
        'Intranet / Extranet'     => ['weight' => 0.0,  'hint' => 'explique la procédure telle que documentée, sans reformulation excessive'],
        'Application web'        => ['weight' => -0.5, 'hint' => 'reste centré sur l’usage visible, évite les suppositions techniques'],
        'PWA'                     => ['weight' => -0.5, 'hint' => 'reste centré sur l’usage visible, évite les suppositions techniques'],
        'Site événementiel'      => ['weight' => -0.5, 'hint' => 'présente les informations factuelles disponibles sans compléter les manques'],
    ],

    // Historique conversationnel : ajustements selon la progression.
    'progression' => [
        // Au tout premier message, on plafonne la profondeur pour éviter
        // de "vider toute la base de connaissances" avant même de connaître le besoin.
        'first_message_cap' => 3, // ResponseDepth::Normal max, sauf indice explicite fort (>= 2.0)
        'first_message_cap_override_threshold' => 2.0,

        // Chaque relance sur le même sujet (follow-up) pousse légèrement vers plus de détail :
        // l'utilisateur montre un intérêt soutenu.
        'per_followup_weight' => 0.4,
        'max_followup_bonus' => 1.5,
    ],

    // Détection de polarité pour les réponses courtes ("oui"/"non") qui
    // suivent un message assistant proposant explicitement d'approfondir.
    // Patterns anchorés (^...$) : ne matchent que des réponses courtes,
    // jamais une sous-chaîne dans une phrase plus longue (ex: "non-négociable"
    // ne doit pas matcher "non").
    'reply_polarity' => [
        'affirmative_patterns' => [
            '/^\s*(oui|ouais|ouaip|d\'accord|d\'acc|ok|okay|vas-?y|continue|je veux bien|avec plaisir|volontiers|allez[- ]?y|dis[- ]?m\'en plus)\s*[.!]?\s*$/ui',
        ],
        'negative_patterns' => [
            '/^\s*(non|non merci|non ça (ira|va)|pas (besoin|maintenant|pour l\'instant)|c\'est bon(?: merci)?|ça ira|laisse(?:z)? tomber|pas la peine|non pas vraiment)\s*[.!]?\s*$/ui',
        ],
        // Poids symétriques : une négation retire volontairement autant
        // qu'une affirmation ajoute, pour que "non" produise un effet aussi
        // net que "oui" — pas une simple absence de bonus.
        'affirmative_weight' => 1.5,
        'negative_weight' => -1.5,
    ],

    // Complexité intrinsèque de la question.
    'question_complexity' => [
        'long_question_word_threshold' => 25,
        'long_question_weight' => 0.5,
        'sub_query_weight_per_item' => 0.3, // decomposition avec beaucoup de sub_queries = sujet complexe
        'max_sub_query_bonus' => 1.2,
    ],

    // Budget de tokens technique par palier de profondeur (paramètre d'appel API,
    // pas une règle affichée dans le prompt).
    'max_tokens_by_depth' => [
        1 => 120,  // Minimal
        2 => 220,  // Short
        3 => 380,  // Normal
        4 => 650,  // Detailed
        5 => 900,  // Expert
    ],

    // Seuil utilisé par SingleHopPipelineService (une seule passe de retrieval) :
    // en dessous de ce nombre de chunks retenus, on considère qu'une question
    // de clarification vaut mieux qu'une réponse partielle/spéculative.
    'clarifying_question_chunk_threshold' => 2,

    // Seuil utilisé par MultiHopPipelineServiceV2 (retrieval itératif, chunks
    // cumulés sur plusieurs hops) : ici on ne compte pas les chunks (un total
    // cumulé ne reflète pas la densité réelle de matière trouvée) mais on
    // réutilise le score de confiance déjà calculé par ce pipeline
    // (coverage/quality/diversity). Volontairement aligné sur le seuil 0.4
    // déjà utilisé par MultiHopPipelineServiceV2::shouldStop() pour détecter
    // une stagnation, afin de ne pas introduire une deuxième notion de
    // "contexte pauvre" qui contredirait celle qui existe déjà dans ce pipeline.
    'clarifying_question_confidence_threshold' => 0.4,
];
