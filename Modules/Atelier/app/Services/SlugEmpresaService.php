<?php

namespace Modules\Atelier\Services;

use Illuminate\Support\Str;
use Modules\Core\Models\Empresa;

/**
 * Garante que a empresa tem um slug para a sua página pública (/loja/{slug}).
 *
 * A migration só faz backfill das empresas que JÁ existiam quando foi
 * corrida; empresas registadas depois (RegistoEmpresaService, no Core, não
 * sabe que slugs existem) ficam com slug nulo até aqui — a primeira vez que
 * o administrador guarda o perfil público. Lazy de propósito, para não
 * alterar o fluxo de registo do Core.
 *
 * 'slug' não está no $fillable de Empresa (o campo é deste módulo), por isso
 * usa forceFill() — e não update() — para o gravar.
 */
class SlugEmpresaService
{
    public function garantir(Empresa $empresa): string
    {
        if ($empresa->slug) {
            return $empresa->slug;
        }

        $base = Str::slug($empresa->nome_comercial) ?: 'empresa-'.$empresa->id;
        $slug = $base;
        $sufixo = 1;

        // withTrashed(): a unique constraint cobre também empresas apagadas.
        while (Empresa::withTrashed()->where('slug', $slug)->where('id', '!=', $empresa->id)->exists()) {
            $slug = "{$base}-{$sufixo}";
            $sufixo++;
        }

        $empresa->forceFill(['slug' => $slug])->save();

        return $slug;
    }
}
