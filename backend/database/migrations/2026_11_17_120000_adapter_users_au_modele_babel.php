<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * La table `users` de Laravel est conservée (Sanctum et Filament s'appuient dessus),
 * puis alignée sur UTILISATEUR du MLD : pseudo, role, suspendu_le, derniere_connexion_le,
 * cree_le. `password` garde son nom Laravel (mot_de_passe_hache du MLD) et `email` existe déjà.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pseudo', 50)->nullable()->unique()->after('id');
        });

        // Comptes éventuellement créés avant la migration : pseudo provisoire unique.
        DB::table('users')->whereNull('pseudo')->orderBy('id')->each(
            fn ($u) => DB::table('users')->where('id', $u->id)->update(['pseudo' => 'membre'.$u->id])
        );

        Schema::table('users', function (Blueprint $table) {
            $table->string('pseudo', 50)->nullable(false)->change();
            $table->renameColumn('created_at', 'cree_le');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['name', 'email_verified_at', 'updated_at']);
        });

        // Ajouts en dernier : sous SQLite, renommer, supprimer ou modifier une colonne reconstruit
        // la table et perd les CHECK. `role` est donc ajouté une fois les reconstructions faites.
        Schema::table('users', function (Blueprint $table) {
            // CHECK écrit à la main : `enum()` n'en pose pas quand on ajoute une colonne sous SQLite.
            $table->rawColumn('role', "varchar(10) check (role in ('membre', 'admin'))")->default('membre');
            $table->timestamp('suspendu_le')->nullable();
            $table->timestamp('derniere_connexion_le')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->default('');
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->renameColumn('cree_le', 'created_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_pseudo_unique');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['pseudo', 'role', 'suspendu_le', 'derniere_connexion_le']);
        });
    }
};
