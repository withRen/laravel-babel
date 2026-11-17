<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('textes_bannis', function (Blueprint $table) {
            $table->id();
            $table->rawColumn('texte', 'text check (length(texte) between 1 and 3200)'); // normalisé, en clair
            $table->string('empreinte', 64)->unique(); // SHA-256 du texte
            $table->text('motif')->nullable();
            $table->foreignId('origine_id')->constrained('bibliotheques')->restrictOnDelete();
            $table->enum('statut', ['propose', 'applique', 'rejete'])->default('propose');
            $table->timestamp('decide_le')->nullable();
            $table->timestamp('cree_le')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('textes_bannis');
    }
};
