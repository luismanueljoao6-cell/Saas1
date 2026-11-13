<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('armazens')) {
            return;
        }

        Schema::create('armazens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('codigo', 40);
            $table->string('nome', 150);
            $table->text('descricao')->nullable();
            $table->string('morada', 255)->nullable();
            $table->boolean('principal')->default(false);
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            $table->unique(['empresa_id', 'codigo'], 'armazens_empresa_codigo_unique');
            $table->index(['empresa_id', 'ativo', 'principal'], 'armazens_empresa_estado_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('armazens');
    }
};
