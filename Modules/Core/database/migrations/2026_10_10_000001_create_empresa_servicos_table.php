<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresa_servicos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('servico', 30);
            $table->boolean('ativo')->default(true);
            $table->timestamp('aderido_em')->useCurrent();
            $table->timestamps();

            $table->unique(['empresa_id', 'servico']);
        });

        // Empresas que já existiam antes desta funcionalidade mantêm o
        // acesso a tudo — ninguém perde nada ao atualizar.
        $agora = now();
        $linhas = [];

        foreach (DB::table('empresas')->pluck('id') as $empresaId) {
            foreach (['faturacao', 'atelier', 'estudio'] as $servico) {
                $linhas[] = [
                    'empresa_id' => $empresaId,
                    'servico' => $servico,
                    'ativo' => true,
                    'aderido_em' => $agora,
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ];
            }
        }

        foreach (array_chunk($linhas, 500) as $lote) {
            DB::table('empresa_servicos')->insert($lote);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('empresa_servicos');
    }
};
