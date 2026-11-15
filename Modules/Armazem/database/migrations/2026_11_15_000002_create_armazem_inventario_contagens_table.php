<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('armazem_inventario_contagens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('inventario_id')->constrained('armazem_inventarios')->restrictOnDelete();
            $table->foreignId('linha_id')->constrained('armazem_inventario_linhas')->restrictOnDelete();
            // Sem FK intencional: o identificador do autor deve sobreviver à remoção da conta.
            $table->unsignedBigInteger('utilizador_id')->nullable();
            $table->decimal('quantidade_sistema', 14, 3);
            $table->decimal('quantidade_contada', 14, 3);
            $table->decimal('diferenca', 14, 3);
            $table->timestamp('registado_em');
            $table->timestamps();

            $table->index(
                ['empresa_id', 'inventario_id', 'utilizador_id'],
                'armazem_inv_contagens_empresa_inv_user_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('armazem_inventario_contagens');
    }
};
