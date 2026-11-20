<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('annotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utilisateur_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('adresse_id')->constrained('adresses')->cascadeOnDelete();
            $table->rawColumn('contenu', 'text check (length(contenu) between 1 and 2000)');
            $table->boolean('est_publique')->default(false);
            $table->timestamp('masque_le')->nullable();
            $table->timestamp('cree_le')->useCurrent();
            $table->timestamp('maj_le')->useCurrent();

            $table->index(['adresse_id', 'est_publique']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('annotations');
    }
};
