<?php

namespace Modules\Core\Contracts;

/**
 * Leitura e validação dos módulos instalados: dependências, serviço que cada
 * módulo de negócio disponibiliza e provisionadores registados. Só LÊ; não
 * ativa nem desativa nada: o serviço contratado por cada empresa é gerido pelo
 * Core (Servico/ServicoController) e a ativação global do módulo é do Nwidart.
 */
interface ModuleRegistryInterface
{
    /**
     * @return array<string, array{nome: string, ativo: bool, requer: array<int, string>, servico: string|null}> por nome de módulo
     */
    public function modulos(): array;

    public function ativo(string $modulo): bool;

    /**
     * Dependências diretas ("requires" do module.json).
     *
     * @return array<int, string>
     */
    public function dependencias(string $modulo): array;

    /**
     * Inconsistências entre módulos, serviços e provisionadores.
     * Vazio = tudo coerente.
     *
     * @return array<int, string>
     */
    public function validar(): array;
}
