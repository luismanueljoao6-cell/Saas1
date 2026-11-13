<?php

namespace Modules\\Armazem\\Services;

use DomainException;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Validation\\ValidationException;
use Modules\\Armazem\\Models\\Armazem;
use Modules\\Core\\Models\\Empresa;
use Modules\\Core\\Services\\TenantManager;

class ArmazemService
{
    public function criar(
        Empresa $empresa,
        string $codigo,
        string $nome,
        ?string $descricao = null,
        ?string $morada = null,
        bool $principal = false
    ): Armazem {
        $codigo = mb_strtoupper(trim($codigo));
        $nome = trim($nome);

        if ($codigo === '' || mb_strlen($codigo) > 40 || $nome === '' || mb_strlen($nome) > 150) {
            throw ValidationException::withMessages([
                'armazem' => 'Indica um código (até 40 caracteres) e um nome (até 150 caracteres).',
            ]);
        }

        return DB::transaction(function () use ($empresa, $codigo, $nome, $descricao, $morada, $principal): Armazem {
            $this->validarTenant($empresa);

            Empresa::query()->whereKey($empresa->id)->lockForUpdate()->firstOrFail();

            if (Armazem::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->where('codigo', $codigo)
                ->exists()) {
                throw ValidationException::withMessages([
                    'codigo' => 'Já existe um armazém com este código nesta empresa.',
                ]);
            }

            if ($principal) {
                Armazem::withoutGlobalScopes()
                    ->where('empresa_id', $empresa->id)
                    ->update(['principal' => false]);
            }

            return Armazem::create([
                'empresa_id' => $empresa->id,
                'codigo' => $codigo,
                'nome' => $nome,
                'descricao' => $descricao,
                'morada' => $morada,
                'principal' => $principal,
                'ativo' => true,
            ]);
        }, attempts: 3);
    }

    public function definirPrincipal(Empresa $empresa, Armazem $armazem): Armazem
    {
        return DB::transaction(function () use ($empresa, $armazem): Armazem {
            $this->validarTenant($empresa);

            Empresa::query()->whereKey($empresa->id)->lockForUpdate()->firstOrFail();

            $alvo = Armazem::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->whereKey($armazem->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $alvo->ativo) {
                throw new DomainException('Um armazém inativo não pode ser definido como principal.');
            }

            Armazem::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->whereKeyNot($alvo->id)
                ->update(['principal' => false]);

            $alvo->forceFill(['principal' => true])->save();

            return $alvo->refresh();
        }, attempts: 3);
    }

    public function obterPrincipal(Empresa $empresa): Armazem
    {
        $this->validarTenant($empresa);

        return DB::transaction(function () use ($empresa): Armazem {
            Empresa::query()->whereKey($empresa->id)->lockForUpdate()->firstOrFail();

            $principal = Armazem::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->where('principal', true)
                ->where('ativo', true)
                ->lockForUpdate()
                ->first();

            if ($principal) {
                return $principal;
            }

            $existente = Armazem::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->where('codigo', 'PRINCIPAL')
                ->first();

            if ($existente) {
                $existente->forceFill(['principal' => true, 'ativo' => true])->save();

                return $existente->refresh();
            }

            return Armazem::create([
                'empresa_id' => $empresa->id,
                'codigo' => 'PRINCIPAL',
                'nome' => 'Armazém principal',
                'principal' => true,
                'ativo' => true,
            ]);
        }, attempts: 3);
    }

    private function validarTenant(Empresa $empresa): void
    {
        $tenant = app(TenantManager::class);

        if ($tenant->has() && ! $tenant->emBypass() && $tenant->id() !== (int) $empresa->id) {
            throw new DomainException('O contexto atual não corresponde à empresa do armazém.');
        }
    }
}
