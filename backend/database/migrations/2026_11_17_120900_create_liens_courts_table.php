<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liens_courts', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 8)->unique();
            $table->foreignId('utilisateur_id')->nullable()->constrained('users')->cascadeOnDelete(); // null = visiteur

            // Exactement une cible : adresse (page ou livre) OU collection.
            // CHECK porté par la colonne adresse_id, qui regarde aussi collection_id.
            $table->rawColumn('adresse_id', 'integer check ('
                .'(case when adresse_id is null then 0 else 1 end) + (case when collection_id is null then 0 else 1 end) = 1)')->nullable();
            $table->foreignId('collection_id')->nullable()->constrained('collections')->cascadeOnDelete();
            $table->foreign('adresse_id')->references('id')->on('adresses')->cascadeOnDelete();

            // Un passage à surligner ne va qu'avec une cible adresse.
            $table->rawColumn('passage', 'text check (passage is null or (adresse_id is not null and length(passage) between 1 and 3200))')->nullable();
            $table->unsignedInteger('visites')->default(0);
            // Sans compte, le lien expire obligatoirement.
            $table->rawColumn('expire_le', 'timestamp check (utilisateur_id is not null or expire_le is not null)')->nullable();
            $table->timestamp('masque_le')->nullable();
            $table->timestamp('cree_le')->useCurrent();

            $table->index('adresse_id');
            $table->index('collection_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liens_courts');
    }
};
