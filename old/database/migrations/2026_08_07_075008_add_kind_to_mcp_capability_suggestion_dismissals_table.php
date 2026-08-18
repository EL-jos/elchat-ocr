<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * mcp_capability_suggestion_dismissals sert maintenant aux DEUX moteurs de
 * recommandation (connecteurs ET combos d'actions) — `kind` distingue les
 * deux espaces de clés, qui peuvent légitimement partager le même `key`
 * sans se confondre.
 *
 * ⚠️ Idempotente à dessein : MySQL exécute chaque ALTER immédiatement (DDL
 * non transactionnel), donc un run précédent interrompu en cours de route
 * (erreur de nom d'index trop long, etc.) peut avoir laissé la table dans
 * un état intermédiaire. Chaque étape vérifie l'état réel avant d'agir,
 * pour être rejouable sans erreur quel que soit le point d'arrêt précédent.
 */
return new class extends Migration
{
    private const UNIQUE_INDEX = 'mcp_cap_dismissals_site_key_kind_unique';
    private const OLD_UNIQUE_INDEX = 'mcp_capability_suggestion_dismissals_site_id_playbook_key_unique';
    private const FK_NAME = 'mcp_capability_suggestion_dismissals_site_id_foreign';
    private const TABLE = 'mcp_capability_suggestion_dismissals';

    public function up(): void
    {
        if ($this->foreignKeyExists(self::FK_NAME)) {
            Schema::table(self::TABLE, fn (Blueprint $t) => $t->dropForeign(self::FK_NAME));
        }

        if ($this->indexExists(self::OLD_UNIQUE_INDEX)) {
            Schema::table(self::TABLE, fn (Blueprint $t) => $t->dropUnique(self::OLD_UNIQUE_INDEX));
        }

        if (!Schema::hasColumn(self::TABLE, 'kind')) {
            Schema::table(self::TABLE, fn (Blueprint $t) => $t->string('kind')->default('connector_combo')->after('playbook_key'));
        }

        if (!$this->indexExists(self::UNIQUE_INDEX)) {
            Schema::table(self::TABLE, fn (Blueprint $t) => $t->unique(['site_id', 'playbook_key', 'kind'], self::UNIQUE_INDEX));
        }

        if (!$this->foreignKeyExists(self::FK_NAME)) {
            Schema::table(self::TABLE, fn (Blueprint $t) => $t->foreign('site_id')->references('id')->on('sites')->onDelete('cascade'));
        }
    }

    public function down(): void
    {
        if ($this->foreignKeyExists(self::FK_NAME)) {
            Schema::table(self::TABLE, fn (Blueprint $t) => $t->dropForeign(self::FK_NAME));
        }
        if ($this->indexExists(self::UNIQUE_INDEX)) {
            Schema::table(self::TABLE, fn (Blueprint $t) => $t->dropUnique(self::UNIQUE_INDEX));
        }
        if (Schema::hasColumn(self::TABLE, 'kind')) {
            Schema::table(self::TABLE, fn (Blueprint $t) => $t->dropColumn('kind'));
        }
        if (!$this->indexExists(self::OLD_UNIQUE_INDEX)) {
            Schema::table(self::TABLE, fn (Blueprint $t) => $t->unique(['site_id', 'playbook_key'], self::OLD_UNIQUE_INDEX));
        }
        if (!$this->foreignKeyExists(self::FK_NAME)) {
            Schema::table(self::TABLE, fn (Blueprint $t) => $t->foreign('site_id')->references('id')->on('sites')->onDelete('cascade'));
        }
    }

    private function indexExists(string $indexName): bool
    {
        $db = DB::getDatabaseName();
        return DB::table('information_schema.statistics')
            ->where('table_schema', $db)
            ->where('table_name', self::TABLE)
            ->where('index_name', $indexName)
            ->exists();
    }

    private function foreignKeyExists(string $fkName): bool
    {
        $db = DB::getDatabaseName();
        return DB::table('information_schema.table_constraints')
            ->where('table_schema', $db)
            ->where('table_name', self::TABLE)
            ->where('constraint_name', $fkName)
            ->where('constraint_type', 'FOREIGN KEY')
            ->exists();
    }
};
