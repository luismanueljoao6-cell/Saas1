<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planos', function (Blueprint $table) {
            // Serviços que o pacote inclui (ver Modules\Core\Support\Servico).
            // Nulo = o plano não altera os serviços da empresa.
            $table->json('servicos')->nullable()->after('limites');
        });
    }

    public function down(): void
    {
        Schema::table('planos', function (Blueprint $table) {
            $table->dropColumn('servicos');
        });
    }
};
