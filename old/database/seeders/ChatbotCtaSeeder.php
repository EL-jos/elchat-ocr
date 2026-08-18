<?php

namespace Database\Seeders;

use App\Models\ChatbotCta;
use App\Models\ChatBotCTARule;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ChatbotCtaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $sites = [
            [
                'id' => '1e0d17be-c7ef-4713-9082-3e6b2845afc6', // PlanetDesign
                'label_prefix' => 'PlanetDesign'
            ],
            [
                'id' => '7c6319a1-656c-4c68-a844-1c1215a637da', // Led's Run
                'label_prefix' => 'LedsRun'
            ]
        ];

        foreach ($sites as $site) {

            // Exemples de CTA
            $ctasData = [
                [
                    'label' => "{$site['label_prefix']} - Voir le catalogue",
                    'action' => 'open_url',
                    'value' => 'https://example.com/catalog',
                    'position' => 1,
                    'max_display' => 5,
                    'is_active' => true,
                    'style' => 'primary',
                    'rules' => [
                        ['rule_type' => 'intent', 'rule_value' => 'information'],
                        ['rule_type' => 'keyword', 'rule_value' => 'information']
                    ]
                ],
                [
                    'label' => "{$site['label_prefix']} - Contactez-nous",
                    'action' => 'email',
                    'value' => 'contact@example.com',
                    'position' => 2,
                    'max_display' => 3,
                    'is_active' => true,
                    'style' => 'secondary',
                    'rules' => [
                        ['rule_type' => 'intent', 'rule_value' => 'contact'],
                        ['rule_type' => 'keyword', 'rule_value' => 'contact']
                    ]
                ],
                [
                    'label' => "{$site['label_prefix']} - WhatsApp",
                    'action' => 'whatsapp',
                    'value' => '+212600000000',
                    'position' => 3,
                    'max_display' => 2,
                    'is_active' => true,
                    'style' => 'success',
                    'rules' => [
                        ['rule_type' => 'intent', 'rule_value' => 'support'],
                        ['rule_type' => 'keyword', 'rule_value' => 'whatsapp']
                    ]
                ]
            ];

            foreach ($ctasData as $ctaData) {
                $cta = ChatbotCta::create([
                    'id' => (string) Str::uuid(),
                    'site_id' => $site['id'],
                    'label' => $ctaData['label'],
                    'action' => $ctaData['action'],
                    'value' => $ctaData['value'],
                    'position' => $ctaData['position'],
                    'max_display' => $ctaData['max_display'],
                    'is_active' => $ctaData['is_active'],
                    'style' => $ctaData['style'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach ($ctaData['rules'] as $rule) {
                    ChatBotCTARule::create([
                        'id' => (string) Str::uuid(),
                        'cta_id' => $cta->id,
                        'rule_type' => $rule['rule_type'],
                        'rule_value' => $rule['rule_value'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        $this->command->info('Chatbot CTAs and rules seeded successfully.');
    }
}
