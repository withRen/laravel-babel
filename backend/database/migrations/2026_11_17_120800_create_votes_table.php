<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('votes', function (Blueprint $table) {
            $table->foreignId('utilisateur_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('adresse_id')->constrained('adresses')->cascadeOnDelete();
            $table->rawColumn('valeur', 'integer check (valeur in (1, -1))');
            $table->timestamp('cree_le')->useCurrent();

            // Un vote par membre et par page.
            $table->primary(['utilisateur_id', 'adresse_id']);
            $table->index('adresse_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('votes');
    }
};
