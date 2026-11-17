<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activites_jour', function (Blueprint $table) {
            $table->date('jour')->primary();
            $table->unsignedInteger('pages_lues')->default(0);
            $table->unsignedInteger('recherches')->default(0);
        });

        Schema::create('reglages', function (Blueprint $table) {
            $table->string('cle', 60)->primary();
            $table->text('valeur');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reglages');
        Schema::dropIfExists('activites_jour');
    }
};
