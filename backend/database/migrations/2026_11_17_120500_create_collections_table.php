<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utilisateur_id')->constrained('users')->cascadeOnDelete();
            $table->string('nom', 120);
            $table->text('description')->nullable();
            $table->boolean('est_publique')->default(false);
            $table->timestamp('masque_le')->nullable();
            $table->timestamp('cree_le')->useCurrent();
            $table->timestamp('maj_le')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collections');
    }
};
