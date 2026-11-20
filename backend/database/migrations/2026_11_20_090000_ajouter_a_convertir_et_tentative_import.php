<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Import d'un compte (M12, règle 20) : il faut savoir quelles adresses restent à convertir.
 *
 * Une page d'une autre bibliothèque peut être voulue (M13 : « je garde cette page de Babel 5 ») ou
 * importée et à convertir. Rien dans les tables ne les distingue : `a_convertir` le dit, sur les trois
 * tables que l'import recrée. La date de la dernière tentative est sur le membre (GET /moi/import).
 *
 * Colonnes AJOUTÉES (jamais renommées ni supprimées) : sous SQLite, modifier une colonne reconstruit
 * la table et ferait perdre ses CHECK.
 */
return new class extends Migration
{
    private const TABLES = ['marque_pages', 'collection_pages', 'annotations'];

    public function up(): void
    {
        foreach (self::TABLES as $nomTable) {
            Schema::table($nomTable, function (Blueprint $table) {
                $table->boolean('a_convertir')->default(false);
            });
        }

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('import_tentative_le')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('import_tentative_le');
        });

        foreach (self::TABLES as $nomTable) {
            Schema::table($nomTable, function (Blueprint $table) {
                $table->dropColumn('a_convertir');
            });
        }
    }
};
