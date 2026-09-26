<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabela central do multi-tenancy: cada linha é uma empresa (inquilino).
     * Todas as tabelas de negócio das outras módulos devem referenciar
     * empresas.id através de uma coluna empresa_id.
     */
    public function up(): void
    {
        Schema::create('empresas', function (Blueprint $table) {
            $table->id();

            // Identificação comercial e fiscal
            $table->string('nome_comercial');
            $table->string('nome_legal')->nullable()->comment('Razão social, se diferente do nome comercial');

            // NIF: o formato exato (nº de dígitos, prefixos por tipo de entidade)
            // depende do regime em vigor da AGT. Mantém-se aqui uma validação
            // permissiva (ver RegistarEmpresaRequest); confirma o formato
            // atual junto da AGT antes de endurecer a regra.
            $table->string('nif')->unique();

            $table->string('regime_fiscal')->nullable()->comment('Ex.: Regime Geral, Regime Transitório — confirmar nomenclatura atual da AGT');

            // Contacto e morada
            $table->string('morada')->nullable();
            $table->string('municipio')->nullable();
            $table->string('provincia')->nullable();
            $table->string('telefone')->nullable();
            $table->string('email')->nullable();
            $table->string('logotipo_path')->nullable();

            // Estado da subscrição SaaS (ver Módulo de Subscrições e Pagamentos)
            $table->enum('estado_subscricao', ['trial', 'ativa', 'pendente', 'suspensa', 'expirada'])
                ->default('trial');
            $table->timestamp('subscricao_expira_em')->nullable();
            $table->timestamp('periodo_tolerancia_ate')->nullable()
                ->comment('Fim do grace period: acesso de leitura permitido até esta data, sem emissão de novas faturas');

            // Configurações livres específicas da empresa (branding, preferências, etc.)
            $table->json('configuracoes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('estado_subscricao');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresas');
    }
};
