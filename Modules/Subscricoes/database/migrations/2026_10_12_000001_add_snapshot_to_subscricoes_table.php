<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscricoes', function (Blueprint $table) {
            // Fotografia dos termos contratados. Alterações futuras ao plano
            // não devem modificar retroativamente uma subscrição já comprada.
            $table->unsignedInteger('periodo_dias')->nullable();
            $table->json('limites')->nullable();
            $table->json('servicos')->nullable();
        });

        DB::table('subscricoes')
            ->select(['id', 'plano_id'])
            ->orderBy('id')
            ->chunkById(100, function ($subscricoes): void {
                foreach ($subscricoes as $subscricao) {
                    $plano = DB::table('planos')
                        ->where('id', $subscricao->plano_id)
                        ->first(['periodo_dias', 'limites', 'servicos']);

                    if (! $plano) {
                        continue;
                    }

                    DB::table('subscricoes')
                        ->where('id', $subscricao->id)
                        ->update([
                            'periodo_dias' => $plano->periodo_dias,
                            'limites' => $plano->limites,
                            'servicos' => $plano->servicos,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('subscricoes', function (Blueprint $table) {
            $table->dropColumn(['periodo_dias', 'limites', 'servicos']);
        });
    }
};
