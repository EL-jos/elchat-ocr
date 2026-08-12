<?php

use App\Domain\Email\Providers\MailtrapEmailProvider;
use App\Domain\Email\Providers\PostmarkEmailProvider;
use App\Domain\Email\Providers\SesEmailProvider;

return [
    /*
     * Fournisseur email actif — un seul à la fois. Changer de fournisseur
     * = changer cette valeur + s'assurer que sa classe est enregistrée
     * ci-dessous. Rien d'autre dans l'application n'a besoin de changer.
     */
    'default' => env('EMAIL_PROVIDER', 'postmark'),

    'providers' => [
        'ses' => SesEmailProvider::class,
        // 'mailgun' => \App\Domain\Email\Providers\MailgunEmailProvider::class, // futur
        'postmark' => PostmarkEmailProvider::class, // futur
        'mailtrap' => MailtrapEmailProvider::class,
        // 'sendgrid' => \App\Domain\Email\Providers\SendgridEmailProvider::class, // futur
    ],
];
