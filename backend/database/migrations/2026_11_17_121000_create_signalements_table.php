<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signalements', function (Blueprint $table) {
            $table->id();

            // Exactement une cible parmi annotation, collection, lien court :
            // la somme des colonnes renseignées vaut 1 (CHECK porté par annotation_id).
            $table->rawColumn('annotation_id', 'integer check ('
                .'(case when annotation_id is null then 0 else 1 end)'
                .' + (case when collection_id is null then 0 else 1 end)'
                .' + (case when lien_court_id is null then 0 else 1 end) = 1)')->nullable();
            $table->foreignId('collection_id')->nullable()->constrained('collections')->cascadeOnDelete();
            $table->foreignId('lien_court_id')->nullable()->constrained('liens_courts')->cascadeOnDelete();
            $table->foreign('annotation_id')->references('id')->on('annotations')->cascadeOnDelete();

            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete(); // null = visiteur
            $table->text('motif');
            $table->enum('statut', ['en_attente', 'traite', 'rejete'])->default('en_attente');
            $table->foreignId('traite_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('traite_le')->nullable();
            $table->timestamp('cree_le')->useCurrent();

            $table->index('statut');
            $table->index('annotation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signalements');
    }
};
