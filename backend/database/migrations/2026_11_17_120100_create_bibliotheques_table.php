<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bibliotheques', function (Blueprint $table) {
            $table->id();
            $table->string('identifiant', 40)->unique(); // stable : jamais l'URL
            $table->string('nom');
            $table->text('description')->nullable();
            $table->string('url_actuelle'); // peut changer
            $table->boolean('est_locale')->default(false);
            $table->enum('etat', ['en_ligne', 'hors_ligne', 'inconnu'])->default('inconnu');
            $table->unsignedInteger('echecs_consecutifs')->default(0);
            $table->timestamp('dernier_contact_le')->nullable();
            $table->timestamp('vue_annuaire_le')->nullable();
            $table->string('empreinte_cle', 64)->nullable(); // l'empreinte seule, jamais la clé
            $table->timestamp('cle_changee_le')->nullable();
        });

        // « Une seule ligne vraie » : un index unique qui ne porte que sur les lignes locales.
        match (DB::getDriverName()) {
            'sqlite', 'pgsql' => DB::statement(
                'create unique index bibliotheques_une_seule_locale on bibliotheques (est_locale) where est_locale = '
                .(DB::getDriverName() === 'pgsql' ? 'true' : '1')
            ),
            // MySQL / MariaDB n'ont pas d'index partiel : colonne générée, NULL hors ligne locale.
            default => DB::statement(
                'alter table bibliotheques add column locale_unique tinyint generated always as (if(est_locale, 1, null)) virtual, '
                .'add unique index bibliotheques_une_seule_locale (locale_unique)'
            ),
        };
    }

    public function down(): void
    {
        Schema::dropIfExists('bibliotheques');
    }
};
