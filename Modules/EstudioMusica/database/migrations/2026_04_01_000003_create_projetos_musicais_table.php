<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projetos_musicais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('cliente_id')->constrained('clientes')
                ->comment('O artista/banda dono do projeto');

            $table->foreignId('produtor_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('engenheiro_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->string('nome')->comment('Ex.: "Álbum X" ou "Single Y"');
            $table->string('estilo')->nullable();
            $table->date('prazo_entrega')->nullable();
            $table->unsignedSmallInteger('bpm')->nullable();
            $table->string('tom_base')->nullable();

            // Fluxo de 8 estados pedido no requisito (secção A). Guardado
            // como string simples (não enum nativo do MySQL) para poder
            // ser alterado só com uma migration de config, tal como o
            // resto do projeto evita PHP enum classes — ver
            // config/estudiomusica.php -> estados_projeto para os rótulos.
            $table->string('estado')->default('agendado');

            // Modelo de cobrança (secção E): por hora (soma das sessões
            // com check-in/check-out) ou por pacote fechado.
            $table->enum('tipo_cobranca', ['hora', 'pacote'])->default('hora');
            $table->decimal('valor_pacote', 14, 2)->nullable()
                ->comment('Só usado quando tipo_cobranca = pacote');
            $table->decimal('percentual_sinal', 5, 2)->nullable()
                ->comment('Nulo = usa estudiomusica.percentual_sinal_padrao');

            $table->text('observacoes')->nullable();

            $table->timestamp('concluido_em')->nullable()
                ->comment('Definido só quando estado passa a "concluido" — usado pelo follow-up de 30 dias (secção F), não se pode inferir de updated_at porque qualquer edição posterior o mudaria');

            // Galeria/Portfólio da landing page pública (secção G) — só
            // projetos marcados aqui pelo Administrador (a "autorização
            // prévia dos artistas" do requisito, registada como uma ação
            // do staff) aparecem no site público.
            $table->boolean('destaque_portfolio')->default(false);
            $table->string('link_publico')->nullable()
                ->comment('URL do player público Spotify/YouTube, mostrado na galeria do portfólio');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['empresa_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projetos_musicais');
    }
};
