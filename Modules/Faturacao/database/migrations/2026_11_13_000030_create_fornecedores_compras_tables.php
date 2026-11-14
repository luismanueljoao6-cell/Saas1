<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fornecedores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('nome', 180);
            $table->string('nif', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('telefone', 40)->nullable();
            $table->string('morada', 255)->nullable();
            $table->string('pais', 2)->default('AO');
            $table->text('observacoes')->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['empresa_id', 'nome']);
            $table->unique(['empresa_id', 'nif']);
        });

        Schema::create('compras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('fornecedor_id')->constrained('fornecedores')->restrictOnDelete();
            $table->string('numero', 40);
            $table->date('data_documento');
            $table->date('data_vencimento')->nullable();
            $table->string('referencia_fornecedor', 80)->nullable();
            $table->string('estado', 20)->default('rascunho');
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('total_iva', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->text('observacoes')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recebida_em')->nullable();
            $table->timestamps();
            $table->unique(['empresa_id', 'numero']);
            $table->index(['empresa_id', 'estado', 'data_documento']);
        });

        Schema::create('compra_linhas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('compra_id')->constrained('compras')->cascadeOnDelete();
            $table->foreignId('produto_id')->constrained('produtos')->restrictOnDelete();
            $table->string('descricao', 255);
            $table->decimal('quantidade', 14, 3);
            $table->decimal('preco_unitario', 18, 2);
            $table->decimal('taxa_iva', 5, 2)->default(0);
            $table->decimal('subtotal', 18, 2);
            $table->decimal('valor_iva', 18, 2);
            $table->decimal('total', 18, 2);
            $table->timestamps();
            $table->index(['empresa_id', 'compra_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compra_linhas');
        Schema::dropIfExists('compras');
        Schema::dropIfExists('fornecedores');
    }
};
