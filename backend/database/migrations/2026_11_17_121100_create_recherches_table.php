<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recherches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utilisateur_id')->constrained('users')->cascadeOnDelete();
            $table->rawColumn('texte', 'text check (length(texte) between 1 and 3200)'); // normalisé
            $table->enum('mode', ['exact', 'bruit']);
            $table->enum('portee', ['locale', 'reseau']);
            $table->timestamp('cree_le')->useCurrent();

            $table->index(['utilisateur_id', 'cree_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recherches');
    }
};
