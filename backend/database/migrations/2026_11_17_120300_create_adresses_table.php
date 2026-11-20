<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bibliotheque_id')->constrained('bibliotheques')->restrictOnDelete();
            $table->rawColumn('hexagone', 'text check (length(hexagone) between 1 and 3004)'); // base 36
            $table->rawColumn('mur', 'integer check (mur between 1 and 4)');
            $table->rawColumn('etagere', 'integer check (etagere between 1 and 5)');
            $table->rawColumn('volume', 'integer check (volume between 1 and 32)');
            $table->rawColumn('page', 'integer check (page is null or page between 1 and 410)')->nullable(); // null = livre entier
            $table->string('empreinte', 64);        // SHA-256 de l'adresse
            $table->string('empreinte_livre', 64);  // SHA-256 de l'adresse sans la page
            $table->foreignId('texte_banni_id')->nullable()->constrained('textes_bannis')->nullOnDelete();
            $table->timestamp('cree_le')->useCurrent();

            // L'hexagone (3 004 caractères) ne peut pas être indexé : l'unicité passe par l'empreinte.
            $table->unique(['bibliotheque_id', 'empreinte']);
            $table->index(['bibliotheque_id', 'empreinte_livre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adresses');
    }
};
