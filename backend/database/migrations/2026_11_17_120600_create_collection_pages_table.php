<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_pages', function (Blueprint $table) {
            $table->foreignId('collection_id')->constrained('collections')->cascadeOnDelete();
            $table->rawColumn('position', 'integer check (position >= 1)');
            $table->foreignId('adresse_id')->constrained('adresses')->cascadeOnDelete();

            // Clé (collection, position) : la même page peut revenir à deux positions.
            $table->primary(['collection_id', 'position']);
            $table->index('adresse_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_pages');
    }
};
