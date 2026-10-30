<?php

namespace Modules\Atelier\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Direito de acesso (Lei n.º 22/11): reúne, em JSON, os dados que o módulo
 * Atelier guarda sobre um cliente. Consulta direta por empresa+cliente
 * (corre na linha de comandos, sem tenant) — não altera nada.
 *
 *   php artisan atelier:exportar-dados-cliente {empresa_id} {cliente_id} > cliente.json
 */
class ExportarDadosClienteCommand extends Command
{
    protected $signature = 'atelier:exportar-dados-cliente {empresa : ID da empresa} {cliente : ID do cliente}';

    protected $description = 'Exporta em JSON os dados do Atelier sobre um cliente (direito de acesso).';

    public function handle(): int
    {
        $empresaId = (int) $this->argument('empresa');
        $clienteId = (int) $this->argument('cliente');

        $cliente = DB::table('clientes')->where('empresa_id', $empresaId)->where('id', $clienteId)->first();

        if (! $cliente) {
            $this->error('Cliente não encontrado nesta empresa.');

            return self::FAILURE;
        }

        $pedidos = DB::table('atelier_pedidos')
            ->where('empresa_id', $empresaId)
            ->where('cliente_id', $clienteId)
            ->get();

        $pedidoIds = $pedidos->pluck('id');

        $dados = [
            'exportado_em' => now()->toIso8601String(),
            'cliente' => $cliente,
            'crm' => DB::table('atelier_clientes_crm')->where('empresa_id', $empresaId)->where('cliente_id', $clienteId)->get(),
            'medidas' => DB::table('atelier_medidas')->where('empresa_id', $empresaId)->where('cliente_id', $clienteId)->get(),
            'pedidos' => $pedidos,
            'fotos' => DB::table('atelier_pedido_fotos')->whereIn('pedido_id', $pedidoIds)->get(),
            'provas' => DB::table('atelier_provas')->whereIn('pedido_id', $pedidoIds)->get(),
            'envios_de_campanhas' => DB::table('atelier_campanha_envios')->where('cliente_id', $clienteId)->get(),
        ];

        $this->line(json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
