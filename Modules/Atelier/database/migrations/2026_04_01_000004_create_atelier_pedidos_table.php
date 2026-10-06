<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atelier_pedidos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('cliente_id')->constrained('clientes');
            $table->foreignId('medida_id')->nullable()->constrained('atelier_medidas')->nullOnDelete();
            $table->foreignId('responsavel_id')->nullable()->constrained('users')->nullOnDelete();

            $table->enum('tipo_servico', ['confecao_medida', 'ajuste_conserto', 'restauracao', 'figurino']);
            $table->text('descricao');
            $table->string('tecido_cor')->nullable();
            $table->text('aviamentos_necessarios')->nullable();

            $table->enum('status', [
                'pendente', 'em_corte', 'em_costura', 'primeira_prova',
                'ajustes', 'pronto_para_retirada', 'entregue', 'cancelado',
            ])->default('pendente');
            $table->text('motivo_cancelamento')->nullable();

            $table->date('data_prevista_entrega')->nullable();
            $table->date('prazo_interno')->nullable();

            // Preço acordado (sem IVA) e a taxa aplicada no momento da
            // criação — guardada aqui, não lida sempre de config('faturacao'),
            // para que alterar a taxa geral no futuro nunca mude o valor de
            // um pedido já em curso.
            $table->decimal('valor_orcamento', 14, 2)->default(0);
            $table->decimal('taxa_iva_aplicada', 5, 2)->default(0);

            $table->decimal('percentual_sinal', 5, 2)->nullable();
            $table->decimal('valor_sinal', 14, 2)->nullable();

            // Documentos fiscais gerados a partir deste pedido (módulo
            // Faturacao). Nunca se edita um documento já emitido (trait
            // Imutavel) — por isso, ao contrário do pedido em si, estas
            // referências, uma vez preenchidas, não voltam a mudar.
            $table->foreignId('recibo_sinal_id')->nullable()->constrained('recibos')->nullOnDelete();
            $table->foreignId('fatura_id')->nullable()->constrained('faturas')->nullOnDelete();
            $table->foreignId('recibo_saldo_final_id')->nullable()->constrained('recibos')->nullOnDelete();

            $table->timestamps();

            $table->index(['empresa_id', 'status']);
            $table->index(['empresa_id', 'cliente_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atelier_pedidos');
    }
};
