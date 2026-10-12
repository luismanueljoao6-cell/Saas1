<?php

namespace Modules\Core\Support;

use Illuminate\Support\Facades\Route;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\MenuRegistry;

/**
 * Estrutura única de navegação: alimenta a barra lateral, os breadcrumbs e a
 * paleta de comandos (Ctrl/Cmd+K), para que as três nunca fiquem dessincronizadas.
 *
 * Regras:
 *  - Um grupo ligado a um serviço (faturacao/atelier/estudio) só aparece se a
 *    empresa aderiu a esse serviço (super admin e contas sem empresa veem tudo).
 *  - Itens só para Administrador ficam escondidos aos restantes papéis.
 *  - Itens cuja rota ainda não existe aparecem como "em breve" (desativados).
 *    Para os esconder: config('core.navegacao_mostrar_em_breve', true) = false.
 *  - Itens registados por outros módulos no MenuRegistry e que não constam
 *    desta lista caem num grupo "Outros", por isso nada desaparece do menu.
 */
class Navegacao
{
    /**
     * @return array<int, array{id: string, rotulo: string, itens: array<int, array{rota: string, rotulo: string, icone: string, url: ?string, ativo: bool, disponivel: bool}>}>
     */
    public static function grupos(): array
    {
        $utilizador = auth()->user();
        $empresa = $utilizador?->empresa;
        $superAdmin = (bool) ($utilizador->is_super_admin ?? false);
        $veTudo = $superAdmin || ! $empresa;
        $administrador = $superAdmin || ($utilizador?->hasRole('Administrador') ?? false);
        $mostrarEmBreve = (bool) config('core.navegacao_mostrar_em_breve', true);

        $definicao = [
            [
                'id' => 'core', 'rotulo' => 'Geral', 'servico' => null,
                'itens' => [
                    ['rota' => 'core.painel', 'rotulo' => 'Dashboard geral', 'icone' => 'painel', 'ativo' => 'core.painel'],
                    ['rota' => 'core.empresa.editar', 'rotulo' => 'Definições da empresa', 'icone' => 'empresa', 'ativo' => 'core.empresa.*', 'params' => [$empresa?->id]],
                    ['rota' => 'core.utilizadores.index', 'rotulo' => 'Utilizadores e papéis', 'icone' => 'utilizadores', 'ativo' => 'core.utilizadores.*', 'admin' => true],
                    ['rota' => 'core.servicos.index', 'rotulo' => 'Serviços contratados', 'icone' => 'pacote', 'ativo' => 'core.servicos.*', 'admin' => true],
                ],
            ],
            [
                'id' => 'faturacao', 'rotulo' => 'Faturação e SAF-T', 'servico' => 'faturacao',
                'itens' => [
                    ['rota' => 'faturacao.faturas.index', 'rotulo' => 'Faturas', 'icone' => 'fatura', 'ativo' => 'faturacao.faturas.*'],
                    ['rota' => 'faturacao.clientes.index', 'rotulo' => 'Clientes', 'icone' => 'cliente', 'ativo' => 'faturacao.clientes.*'],
                    ['rota' => 'faturacao.produtos.index', 'rotulo' => 'Produtos', 'icone' => 'pacote', 'ativo' => 'faturacao.produtos.*'],
                    ['rota' => 'faturacao.saft.criar', 'rotulo' => 'Exportação SAF-T', 'icone' => 'exportar', 'ativo' => 'faturacao.saft.*', 'admin' => true],
                ],
            ],
            [
                'id' => 'subscricoes', 'rotulo' => 'Subscrições e planos', 'servico' => null,
                'itens' => [
                    ['rota' => 'subscricoes.faturacao', 'rotulo' => 'Faturação do SaaS', 'icone' => 'recibo', 'ativo' => 'subscricoes.faturacao'],
                    ['rota' => 'subscricoes.planos', 'rotulo' => 'Plano atual', 'icone' => 'plano', 'ativo' => ['subscricoes.planos', 'subscricoes.iniciar', 'subscricoes.renovacao']],
                    ['rota' => 'subscricoes.pagamentos', 'rotulo' => 'Pagamentos ProxyPay', 'icone' => 'cartao', 'ativo' => ['subscricoes.pagamentos', 'subscricoes.pendente']],
                ],
            ],
            [
                'id' => 'atelier', 'rotulo' => 'Atelier', 'servico' => 'atelier',
                'itens' => [
                    ['rota' => 'atelier.pedidos.index', 'rotulo' => 'Pedidos', 'icone' => 'pedidos', 'ativo' => 'atelier.pedidos.*'],
                    ['rota' => 'atelier.provas.index', 'rotulo' => 'Provas', 'icone' => 'prova', 'ativo' => 'atelier.provas.*'],
                    // A rota existente (atelier.medidas.historico) exige {cliente}; esta é a listagem geral por criar.
                    ['rota' => 'atelier.medidas.index', 'rotulo' => 'Medidas dos clientes', 'icone' => 'medidas', 'ativo' => 'atelier.medidas.*'],
                    ['rota' => 'atelier.orcamentos.index', 'rotulo' => 'Orçamentos', 'icone' => 'orcamento', 'ativo' => 'atelier.orcamentos.*'],
                    ['rota' => 'atelier.portfolio.index', 'rotulo' => 'Portfólio', 'icone' => 'imagem', 'ativo' => 'atelier.portfolio.*'],
                ],
            ],
            [
                'id' => 'estudio', 'rotulo' => 'Estúdio de música', 'servico' => 'estudio',
                'itens' => [
                    ['rota' => 'estudiomusica.projetos.index', 'rotulo' => 'Projetos musicais', 'icone' => 'musica', 'ativo' => 'estudiomusica.projetos.*'],
                    ['rota' => 'estudiomusica.salas.index', 'rotulo' => 'Salas de gravação', 'icone' => 'microfone', 'ativo' => 'estudiomusica.salas.*'],
                    ['rota' => 'estudiomusica.sessoes.index', 'rotulo' => 'Agendamento de sessões', 'icone' => 'calendario', 'ativo' => 'estudiomusica.sessoes.*'],
                    ['rota' => 'estudiomusica.versoes.index', 'rotulo' => 'Versões de áudio', 'icone' => 'audio', 'ativo' => 'estudiomusica.versoes.*'],
                ],
            ],
        ];

        $grupos = [];
        $rotasDefinidas = [];

        foreach ($definicao as $grupo) {
            $itens = [];

            foreach ($grupo['itens'] as $item) {
                $rotasDefinidas[] = $item['rota'];
                $params = $item['params'] ?? [];
                $disponivel = Route::has($item['rota']) && ! in_array(null, $params, true);

                if (($item['admin'] ?? false) && ! $administrador) {
                    continue;
                }

                if (! $disponivel && ! $mostrarEmBreve) {
                    continue;
                }

                $itens[] = [
                    'rota' => $item['rota'],
                    'rotulo' => $item['rotulo'],
                    'icone' => $item['icone'],
                    'url' => $disponivel ? route($item['rota'], $params) : null,
                    'ativo' => $disponivel && request()->routeIs(...(array) $item['ativo']),
                    'disponivel' => $disponivel,
                ];
            }

            $servicoContratado = $grupo['servico'] === null
                || $veTudo
                || $empresa->temServico($grupo['servico']);

            if ($servicoContratado && $itens !== []) {
                $grupos[] = ['id' => $grupo['id'], 'rotulo' => $grupo['rotulo'], 'itens' => $itens];
            }
        }

        // Itens que outros módulos registaram no MenuRegistry e que esta lista não conhece.
        $extras = collect(app(MenuRegistry::class)->itens())
            ->reject(fn (array $item) => in_array($item['rota'], $rotasDefinidas, true))
            ->map(fn (array $item) => [
                'rota' => $item['rota'],
                'rotulo' => $item['rotulo'],
                'icone' => 'pacote',
                'url' => $item['url'],
                'ativo' => request()->routeIs($item['rota']),
                'disponivel' => true,
            ])
            ->values()
            ->all();

        if ($extras !== []) {
            $grupos[] = ['id' => 'outros', 'rotulo' => 'Outros', 'itens' => $extras];
        }

        return $grupos;
    }

    /**
     * Caminho do item ativo: [['rotulo' => 'Faturação e SAF-T', 'url' => null], ['rotulo' => 'Faturas', 'url' => '...']].
     *
     * @param  array<int, array{rotulo: string, itens: array<int, array<string, mixed>>}>  $grupos
     * @return array<int, array{rotulo: string, url: ?string}>
     */
    public static function migalhas(array $grupos): array
    {
        foreach ($grupos as $grupo) {
            foreach ($grupo['itens'] as $item) {
                if ($item['ativo']) {
                    return [
                        ['rotulo' => $grupo['rotulo'], 'url' => null],
                        ['rotulo' => $item['rotulo'], 'url' => $item['url']],
                    ];
                }
            }
        }

        return [];
    }

    /**
     * Rótulo e tom (emerald|amber|red|sky|slate) do estado da subscrição,
     * calculado com as mesmas regras de acesso do resto da plataforma.
     *
     * @return array{rotulo: string, tom: string}
     */
    public static function estadoSubscricao(?Empresa $empresa): array
    {
        if (! $empresa) {
            return ['rotulo' => 'Plataforma', 'tom' => 'slate'];
        }

        if ($empresa->subscricaoAtiva()) {
            return $empresa->estado_subscricao === 'trial'
                ? ['rotulo' => 'Período experimental', 'tom' => 'sky']
                : ['rotulo' => 'Ativo', 'tom' => 'emerald'];
        }

        if ($empresa->emPeriodoDeTolerancia()) {
            return ['rotulo' => 'Em tolerância', 'tom' => 'amber'];
        }

        return match ($empresa->estado_subscricao) {
            'pendente' => ['rotulo' => 'Pagamento pendente', 'tom' => 'amber'],
            'suspensa' => ['rotulo' => 'Suspenso', 'tom' => 'red'],
            default => ['rotulo' => 'Expirado', 'tom' => 'red'],
        };
    }
}
