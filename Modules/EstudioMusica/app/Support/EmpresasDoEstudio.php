<?php

namespace Modules\EstudioMusica\Support;

use Modules\Core\Models\Empresa;
use Modules\Core\Support\Servico;

/**
 * Empresas a quem os jobs agendados (lembretes de sessão, campanhas) podem enviar mensagens
 * em nome do estúdio: têm o serviço Estúdio ativo e acesso (subscrição ativa ou em tolerância).
 * Os jobs varrem TODAS as empresas sem tenant — sem este filtro continuavam a contactar os
 * clientes de estúdios que já cancelaram o serviço ou deixaram a subscrição expirar.
 */
final class EmpresasDoEstudio
{
    /** @return array<int, int> ids das empresas elegíveis */
    public static function ativas(): array
    {
        return Empresa::query()
            ->with('servicos')
            ->get()
            ->filter(fn (Empresa $empresa) => $empresa->temServico(Servico::Estudio) && $empresa->temAcesso())
            ->map(fn (Empresa $empresa) => (int) $empresa->id)
            ->values()
            ->all();
    }
}
