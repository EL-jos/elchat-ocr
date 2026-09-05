<?php

namespace App\Domain\Sales;

use App\Domain\Sales\Contracts\ProspectSourceInterface;
use InvalidArgumentException;

class ProspectingSourceRegistry
{
    /** @param ProspectSourceInterface[] $sources */
    public function __construct(private readonly array $sources) {}

    /** @return array<string, ProspectSourceInterface> */
    public function all(): array
    {
        $indexed = [];
        foreach ($this->sources as $source) {
            $indexed[$source->key()] = $source;
        }

        return $indexed;
    }

    public function get(string $key): ProspectSourceInterface
    {
        $source = $this->all()[$key] ?? null;
        if (! $source) {
            throw new InvalidArgumentException("Source de prospection inconnue : {$key}");
        }

        return $source;
    }

    /** @return array<int, array{key:string,label:string,description:string,attribution?:string,requires:array<int,string>,available:bool,availability_reason?:string}> */
    public function catalog(): array
    {
        return [
            [
                'key' => 'openstreetmap',
                'label' => 'OpenStreetMap / Overpass',
                'description' => 'Entreprises et lieux publics géolocalisés à partir de données OpenStreetMap.',
                'attribution' => '© OpenStreetMap contributors — données sous licence ODbL.',
                'requires' => ['icp.location'],
                'available' => true,
            ],
            [
                'key' => 'web_discovery',
                'label' => 'Web Discovery',
                'description' => 'Répertoires et pages publiques explicitement fournis, avec extraction limitée et respectueuse.',
                'requires' => ['discovery_settings.web_seed_urls'],
                'available' => true,
            ],
            [
                'key' => 'web_search',
                'label' => 'Recherche web autonome',
                'description' => 'Recherche web pilotée par l’IA côté serveur, avec preuves et URLs publiques conservées.',
                'requires' => ['platform.openrouter_api_key'],
                'available' => (bool) config('prospecting.web_search.enabled', true) && (bool) config('mcp.llm.api_key'),
                'availability_reason' => 'Configurez OPENROUTER_API_KEY côté ELChat.',
            ],
            [
                'key' => 'foursquare',
                'label' => 'Foursquare Places',
                'description' => 'Recherche de lieux et entreprises via la base Places de Foursquare.',
                'requires' => ['platform.foursquare_api_key', 'icp.location'],
                'available' => (bool) config('prospecting.foursquare.api_key'),
                'availability_reason' => 'Configurez FOURSQUARE_API_KEY côté ELChat.',
            ],
            [
                'key' => 'here',
                'label' => 'HERE Technologies',
                'description' => 'Recherche géographique d’entreprises et de lieux avec filtrage par zone.',
                'requires' => ['platform.here_api_key', 'icp.location'],
                'available' => (bool) config('prospecting.here.api_key'),
                'availability_reason' => 'Configurez HERE_API_KEY côté ELChat.',
            ],
            [
                'key' => 'tomtom',
                'label' => 'TomTom Developer',
                'description' => 'Recherche de points d’intérêt et coordonnées dans la zone ICP.',
                'requires' => ['platform.tomtom_api_key', 'icp.location'],
                'available' => (bool) config('prospecting.tomtom.api_key'),
                'availability_reason' => 'Configurez TOMTOM_API_KEY côté ELChat.',
            ],
            [
                'key' => 'crm_cold_contact',
                'label' => 'Contacts CRM existants',
                'description' => 'Source de compatibilité pour rechercher des contacts déjà présents dans le CRM.',
                'requires' => ['crm_connector_slug'],
                'available' => true,
            ],
        ];
    }
}
