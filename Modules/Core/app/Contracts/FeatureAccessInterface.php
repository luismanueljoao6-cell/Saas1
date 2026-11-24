<?php

namespace Modules\Core\Contracts;

use Modules\Core\Support\Servico;

/**
 * Pergunta pública: "esta empresa contratou e tem ativo este serviço?".
 *
 * Não é uma feature flag (rollout) nem um limite comercial: os limites do
 * plano continuam em LimitesDaEmpresa e a subscrição no módulo Subscrições.
 */
interface FeatureAccessInterface
{
    /**
     * Fail-closed: sem empresa no contexto devolve sempre false, mesmo que
     * $empresaId seja fornecido; se houver contexto e $empresaId for outra
     * empresa, também devolve false.
     */
    public function servicoAtivo(Servico|string $servico, ?int $empresaId = null): bool;
}
