<?php

/**
 * Taxonomie des capacités abstraites que les connecteurs peuvent offrir.
 * Un outil n'est plus identifié seulement par son connecteur, mais aussi
 * (optionnellement) par CE qu'il accomplit — c'est ce qui permet à une
 * recette de workflow de dire "vérifie les disponibilités" sans savoir si
 * c'est Google Calendar, HubSpot ou un futur connecteur qui s'en charge.
 */
return [
    'scheduling.check_availability' => 'Vérifier les disponibilités',
    'scheduling.create_event' => 'Créer un rendez-vous',
    'scheduling.update_event' => 'Modifier un rendez-vous',
    'scheduling.cancel_event' => 'Annuler un rendez-vous',

    'crm.create_or_update_contact' => 'Créer/mettre à jour un contact CRM',
    'crm.create_opportunity' => 'Créer une opportunité commerciale',
    'crm.log_activity' => "Journaliser une activité (note, appel, email)",
    'crm.create_task' => 'Créer une tâche',
    'crm.qualify_lead' => 'Qualifier un prospect',

    'support.create_ticket' => 'Ouvrir un ticket de support',
    'communication.send_email' => 'Envoyer un email',

    'commerce.search_products' => 'Rechercher des produits',
    'commerce.manage_cart' => 'Gérer le panier',
    'commerce.checkout' => 'Finaliser une commande',
    'commerce.create_account' => 'Créer un compte client',
];
