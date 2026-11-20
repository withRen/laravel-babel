<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marque_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utilisateur_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('adresse_id')->constrained('adresses')->cascadeOnDelete();
            $table->string('titre', 120)->nullable();
            $table->timestamp('cree_le')->useCurrent();

            $table->unique(['utilisateur_id', 'adresse_id']);
            $table->index('adresse_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marque_pages');
    }
};
