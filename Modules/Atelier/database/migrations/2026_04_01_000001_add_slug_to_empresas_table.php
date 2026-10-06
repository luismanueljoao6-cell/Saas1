<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * O Core ainda não tem o conceito de "URL pública por empresa" — até este
 * módulo, toda a aplicação vive atrás de autenticação. A Landing Page
 * institucional (requisito F) é a primeira página pública identificada por
 * tenant, por isso o slug nasce aqui, no Atelier, e não no Core.
 *
 * Se um segundo módulo de negócio vier a precisar do mesmo conceito, faz
 * sentido "promover" este campo para uma migration do próprio Core numa
 * refactorização futura — por agora, mantê-lo aditivo e isolado aqui evita
 * qualquer alteração aos ficheiros existentes do Core.
 *
 * O backfill usa o query builder (DB::table) e não o model Empresa, de
 * propósito: (1) 'slug' não está no $fillable de Empresa, por isso um
 * ->update(['slug' => ...]) via Eloquent seria silenciosamente ignorado;
 * (2) uma migration não deve depender de um model que pode mudar no futuro;
 * (3) a unique constraint cobre TAMBÉM empresas apagadas (soft delete), e o
 * query builder vê-as, ao contrário do model — evita colisões.
 *
 * Empresas registadas DEPOIS desta migration recebem o slug de forma lazy,
 * na primeira vez que o administrador guarda o perfil público (ver
 * Services\SlugEmpresaService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->string('slug')->nullable()->unique()->after('nome_comercial');
        });

        // chunkById (e não chunk/each): estamos a preencher a MESMA coluna
        // que o filtro whereNull('slug') usa — com paginação por offset, as
        // linhas já preenchidas saíam do conjunto e a página seguinte
        // saltava empresas. chunkById pagina pelo id, imune a isto.
        DB::table('empresas')->whereNull('slug')->chunkById(200, function ($empresas) {
            foreach ($empresas as $empresa) {
                $base = Str::slug($empresa->nome_comercial) ?: 'empresa-'.$empresa->id;
                $slug = $base;
                $sufixo = 1;

                while (DB::table('empresas')->where('slug', $slug)->where('id', '!=', $empresa->id)->exists()) {
                    $slug = "{$base}-{$sufixo}";
                    $sufixo++;
                }

                DB::table('empresas')->where('id', $empresa->id)->update(['slug' => $slug]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            // O índice único tem de sair ANTES da coluna — o SQLite recusa
            // apagar uma coluna que ainda faz parte de um índice.
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
