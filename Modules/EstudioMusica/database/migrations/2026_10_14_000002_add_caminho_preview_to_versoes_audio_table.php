<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pré-visualização de baixa qualidade, servida no portal enquanto o
     * projeto não está quitado (o ficheiro original só sai após quitação).
     */
    public function up(): void
    {
        Schema::table('versoes_audio', function (Blueprint $table) {
            $table->string('caminho_preview')->nullable()->after('caminho_ficheiro');
        });
    }

    public function down(): void
    {
        Schema::table('versoes_audio', function (Blueprint $table) {
            $table->dropColumn('caminho_preview');
        });
    }
};
