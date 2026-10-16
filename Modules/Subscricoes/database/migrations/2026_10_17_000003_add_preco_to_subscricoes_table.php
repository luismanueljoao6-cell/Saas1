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
            // Preço e moeda contratados: completam o snapshot de termos.
            $table->decimal('preco', 12, 2)->nullable();
            $table->string('moeda', 3)->nullable();
        });

        DB::table('subscricoes')
            ->select(['id', 'plano_id'])
            ->orderBy('id')
            ->chunkById(100, function ($subscricoes): void {
                foreach ($subscricoes as $subscricao) {
                    // Melhor evidência do preço contratado: o primeiro pagamento
                    // emitido; na falta dele, o preço atual do plano.
                    $origem = DB::table('pagamentos')
                        ->where('subscricao_id', $subscricao->id)
                        ->orderBy('id')
                        ->first(['valor', 'moeda']);

                    $valor = $origem?->valor;
                    $moeda = $origem?->moeda;

                    if ($valor === null) {
                        $plano = DB::table('planos')
                            ->where('id', $subscricao->plano_id)
                            ->first(['preco', 'moeda']);

                        if (! $plano) {
                            continue;
                        }

                        $valor = $plano->preco;
                        $moeda = $plano->moeda;
                    }

                    DB::table('subscricoes')
                        ->where('id', $subscricao->id)
                        ->update(['preco' => $valor, 'moeda' => $moeda]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('subscricoes', function (Blueprint $table) {
            $table->dropColumn(['preco', 'moeda']);
        });
    }
};
