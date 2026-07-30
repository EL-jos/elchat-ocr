<?php

namespace App\Console\Commands;

use App\Models\Payment\Plan;
use App\Services\payment\PayPalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SetupPayPalPlans extends Command
{
    protected $signature   = 'paypal:setup-plans {--force : Recréer même si les IDs existent déjà}';
    protected $description = 'Crée automatiquement les produits et plans PayPal depuis la base de données locale';

    public function __construct(private PayPalService $paypal) {
        parent::__construct();
    }

    public function handle(): int
    {
        $mode = config('paypal.mode', 'sandbox');
        $this->info("🚀 Configuration PayPal — Mode : <comment>{$mode}</comment>");
        $this->newLine();

        // Vérifier les credentials
        $clientId = config("paypal.{$mode}.client_id");
        if (!$clientId || str_starts_with($clientId, 'AX')) {
            $this->error('❌ Credentials PayPal non configurés dans .env');
            $this->warn('   Configurez PAYPAL_SANDBOX_CLIENT_ID et PAYPAL_SANDBOX_CLIENT_SECRET');
            return self::FAILURE;
        }

        // ── Étape 1 : Créer le Product PayPal ────────────────────────────────
        $this->info('📦 Étape 1/3 — Création du Product PayPal...');

        try {
            $product = $this->paypal->createProduct();
            $productId = $product['id'];
            $this->line("   ✅ Product créé : <info>{$productId}</info> ({$product['name']})");
        } catch (\Exception $e) {
            $this->error('   ❌ Erreur : ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->newLine();

        // ── Étape 2 : Créer les Plans pour chaque offre ───────────────────────
        $this->info('📋 Étape 2/3 — Création des Plans PayPal...');

        $plans    = Plan::active()->get();
        $results  = [];
        $hasError = false;

        foreach ($plans as $plan) {
            if ($plan->is_enterprise) {
                $this->line("   ⏭️  <comment>{$plan->name}</comment> → Skipped (Enterprise = contact commercial)");
                continue;
            }

            // Vérifier si déjà configuré
            if (!$this->option('force') && $plan->hasPayPalPlans()) {
                $this->line("   ⏭️  <comment>{$plan->name}</comment> → Déjà configuré (utilisez --force pour recréer)");
                $results[$plan->slug] = [
                    'monthly' => $plan->paypal_plan_monthly,
                    'annual'  => $plan->paypal_plan_annual,
                ];
                continue;
            }

            // Créer plan mensuel
            $this->line("   ⏳ <comment>{$plan->name}</comment> — Mensuel...");
            try {
                $monthly    = $this->paypal->createPlan($productId, $plan, 'monthly');
                $monthlyId  = $monthly['id'];
                $this->line("      ✅ Mensuel  : <info>{$monthlyId}</info>");
            } catch (\Exception $e) {
                $this->error("      ❌ Mensuel  : " . $e->getMessage());
                $hasError = true;
                continue;
            }

            // Créer plan annuel
            $this->line("   ⏳ <comment>{$plan->name}</comment> — Annuel...");
            try {
                $annual   = $this->paypal->createPlan($productId, $plan, 'annual');
                $annualId = $annual['id'];
                $this->line("      ✅ Annuel   : <info>{$annualId}</info>");
            } catch (\Exception $e) {
                $this->error("      ❌ Annuel   : " . $e->getMessage());
                $hasError = true;
                continue;
            }

            $results[$plan->slug] = [
                'monthly' => $monthlyId,
                'annual'  => $annualId,
            ];
        }

        if (empty($results)) {
            $this->error('❌ Aucun plan créé.');
            return self::FAILURE;
        }

        $this->newLine();

        // ── Étape 3 : Sauvegarder les IDs en base ─────────────────────────────
        $this->info('💾 Étape 3/3 — Sauvegarde des IDs PayPal en base de données...');

        DB::transaction(function () use ($results) {
            foreach ($results as $slug => $ids) {
                $updated = Plan::where('slug', $slug)->update([
                    'paypal_plan_monthly' => $ids['monthly'],
                    'paypal_plan_annual'  => $ids['annual'],
                ]);
                if ($updated) {
                    $this->line("   ✅ <comment>{$slug}</comment> → Sauvegardé");
                }
            }
        });

        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════════');
        $this->info('✅  Configuration PayPal terminée avec succès !');
        $this->newLine();

        // ── Tableau récapitulatif ──────────────────────────────────────────────
        $tableData = [];
        foreach ($results as $slug => $ids) {
            $tableData[] = [
                'Plan'    => ucfirst($slug),
                'Monthly' => $ids['monthly'],
                'Annual'  => $ids['annual'],
            ];
        }

        $this->table(['Plan', 'PayPal Plan ID (Mensuel)', 'PayPal Plan ID (Annuel)'], $tableData);

        $this->newLine();
        $this->warn('📌 Prochaine étape :');
        $this->line('   Configurez le webhook PayPal sur le Dashboard Developer :');
        $this->line('   URL : <info>' . url('/paypal/webhook') . '</info>');
        $this->line('   Événements à activer (voir config/paypal.php → webhook_events)');

        if ($hasError) {
            $this->newLine();
            $this->warn('⚠️  Certains plans ont échoué. Vérifiez les logs ci-dessus.');
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
