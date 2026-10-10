<?php

namespace Modules\Core\Services;

use Illuminate\Support\Facades\Route;

/**
 * Resolve o problema de navegação entre módulos sem quebrar a
 * independência entre eles: o Core NUNCA importa nada de Subscricoes ou
 * Faturacao (isso seria o Core a depender de módulos que, por definição,
 * podem não estar instalados). Em vez disso, cada módulo regista os seus
 * próprios itens aqui, no boot() do seu ServiceProvider — o Core só
 * conhece esta interface simples, nunca os módulos em si.
 *
 * Se desativares um módulo (ex.: apagares a pasta Modules/Faturacao), os
 * itens dele simplesmente deixam de ser registados — o menu adapta-se
 * sozinho, sem precisares de tocar no Core.
 */
class MenuRegistry
{
    protected array $itens = [];

    /**
     * $parametros é uma closure só avaliada no momento de desenhar o menu
     * (nunca no momento de registar) — é isso que permite registar, por
     * exemplo, uma rota como 'core.empresa.editar' (que precisa de
     * {empresa}) sem o módulo saber de antemão qual vai ser o utilizador
     * autenticado a ver a página. $visivel segue a mesma lógica, para
     * itens que só devem aparecer a certos papéis (ex.: "Utilizadores"
     * só para administradores) — null significa "sempre visível".
     */
    public function adicionar(
        string $rota,
        string $rotulo,
        int $ordem = 100,
        ?\Closure $parametros = null,
        ?\Closure $visivel = null,
    ): void {
        $this->itens[] = compact('rota', 'rotulo', 'ordem', 'parametros', 'visivel');
    }

    /**
     * @return array<int, array{rota: string, rotulo: string, ordem: int, url: string}>
     */
    public function itens(): array
    {
        return collect($this->itens)
            // Defensivo: se um módulo registou uma rota que por alguma
            // razão não existe (ex.: módulo desativado a meio, cache de
            // rotas desatualizada), simplesmente não aparece — nunca
            // rebenta a página com um erro de rota inexistente.
            ->filter(fn (array $item) => Route::has($item['rota']))
            ->filter(fn (array $item) => $this->servicoDisponivel($item['rota']))
            ->filter(fn (array $item) => $item['visivel'] === null || ($item['visivel'])())
            ->map(fn (array $item) => [
                'rota' => $item['rota'],
                'rotulo' => $item['rotulo'],
                'ordem' => $item['ordem'],
                'url' => route($item['rota'], $item['parametros'] ? ($item['parametros'])() : []),
            ])
            ->sortBy('ordem')
            ->values()
            ->all();
    }

    /**
     * Um item só aparece se a empresa aderiu ao serviço exigido pela própria
     * rota (middleware 'servico:xxx'). Assim o menu e o bloqueio real do
     * acesso têm uma única fonte de verdade — a definição da rota — e
     * nenhum módulo precisa de repetir a regra.
     */
    protected function servicoDisponivel(string $rota): bool
    {
        $utilizador = auth()->user();

        if (! $utilizador || $utilizador->is_super_admin || ! $utilizador->empresa) {
            return true;
        }

        $definicao = Route::getRoutes()->getByName($rota);

        if (! $definicao) {
            return true;
        }

        foreach ($definicao->gatherMiddleware() as $middleware) {
            if (is_string($middleware)
                && str_starts_with($middleware, 'servico:')
                && ! $utilizador->empresa->temServico(substr($middleware, strlen('servico:')))) {
                return false;
            }
        }

        return true;
    }
}
