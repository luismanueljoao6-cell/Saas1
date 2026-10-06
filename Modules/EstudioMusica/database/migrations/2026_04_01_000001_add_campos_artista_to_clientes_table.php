<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Requisito do módulo EstudioMusica: "Ficha do Artista integrada à
     * tabela de clientes" — em vez de criar uma tabela paralela, este
     * módulo estende diretamente Modules\Faturacao\Models\Cliente.
     *
     * Propositadamente NÃO alteramos Modules/Faturacao/app/Models/Cliente.php
     * (fillable/casts) para manter o módulo Faturação intocado e
     * independente deste. Toda a leitura/escrita destes campos passa por
     * Modules\EstudioMusica\Services\PerfilArtistaService, que faz
     * atribuição direta de atributos (bypassa $fillable) e trata a
     * codificação/decodificação JSON de integrantes_banda e
     * links_redes_sociais. Ver esse serviço antes de mexer aqui.
     *
     * data_nascimento não vem pedida na secção "Ficha do Artista" do
     * requisito, mas é acrescentada porque a secção F ("Marketing
     * Automático") pede mensagens de aniversário do artista — sem esta
     * coluna essa funcionalidade não teria como existir.
     */
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->string('nome_artistico')->nullable()->after('nome');
            $table->string('genero_musical')->nullable()->after('nome_artistico');
            $table->json('integrantes_banda')->nullable()
                ->comment('Array JSON de nomes dos integrantes, quando o cliente é uma banda');
            $table->json('links_redes_sociais')->nullable()
                ->comment('Array JSON associativo, ex.: {"spotify": "...", "instagram": "..."}');
            $table->date('data_nascimento')->nullable()
                ->comment('Usado apenas pela campanha de aniversário do módulo EstudioMusica');

            // Portal do Cliente (secção C): "Login / Token Temporário" — um
            // único token por cliente (não por projeto), reenviável a
            // qualquer momento pela Receção. Ver PerfilArtistaService e
            // Http\Middleware\VerificarTokenPortalCliente.
            $table->string('token_portal')->nullable()->unique();
            $table->timestamp('token_portal_expira_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn([
                'nome_artistico',
                'genero_musical',
                'integrantes_banda',
                'links_redes_sociais',
                'data_nascimento',
                'token_portal',
                'token_portal_expira_em',
            ]);
        });
    }
};
