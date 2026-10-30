<?php

namespace Modules\Atelier\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Direito de eliminação (Lei n.º 22/11) dos dados que SÓ o módulo Atelier
 * guarda sobre um cliente: medidas corporais, ficha de CRM/consentimento,
 * fotos dos pedidos e registos de envio de campanhas.
 *
 * NÃO apaga o cliente, os pedidos, faturas nem recibos: os documentos
 * fiscais têm de ser conservados durante o prazo legal. Só depois de
 * esse prazo (e com validação do contabilista) se deve anonimizar o
 * cliente no módulo Faturação.
 *
 *   php artisan atelier:eliminar-dados-cliente {empresa_id} {cliente_id} [--force]
 */
class EliminarDadosClienteCommand extends Command
{
    protected $signature = 'atelier:eliminar-dados-cliente {empresa : ID da empresa} {cliente : ID do cliente} {--force : Não pede confirmação}';

    protected $description = 'Elimina os dados pessoais guardados só pelo Atelier sobre um cliente (mantém documentos fiscais).';

    public function handle(): int
    {
        $empresaId = (int) $this->argument('empresa');
        $clienteId = (int) $this->argument('cliente');

        $cliente = DB::table('clientes')->where('empresa_id', $empresaId)->where('id', $clienteId)->first();

        if (! $cliente) {
            $this->error('Cliente não encontrado nesta empresa.');

            return self::FAILURE;
        }

        $this->warn('Vão ser eliminados, de forma irreversível: medidas, ficha de CRM/consentimento, fotos dos pedidos e registos de envio de campanhas.');
        $this->line('Ficam intactos o cliente, os pedidos, as faturas e os recibos (retenção fiscal).');

        if (! $this->option('force') && ! $this->confirm('Continuar?')) {
            $this->info('Nada foi alterado.');

            return self::FAILURE;
        }

        $pedidoIds = DB::table('atelier_pedidos')
            ->where('empresa_id', $empresaId)
            ->where('cliente_id', $clienteId)
            ->pluck('id');

        $caminhosFotos = DB::table('atelier_pedido_fotos')->whereIn('pedido_id', $pedidoIds)->pluck('caminho');

        DB::transaction(function () use ($empresaId, $clienteId, $pedidoIds) {
            DB::table('atelier_pedidos')->whereIn('id', $pedidoIds)->update(['medida_id' => null]);
            DB::table('atelier_pedido_fotos')->whereIn('pedido_id', $pedidoIds)->delete();
            DB::table('atelier_medidas')->where('empresa_id', $empresaId)->where('cliente_id', $clienteId)->delete();
            DB::table('atelier_clientes_crm')->where('empresa_id', $empresaId)->where('cliente_id', $clienteId)->delete();
            DB::table('atelier_campanha_envios')->where('cliente_id', $clienteId)->delete();
        });

        foreach ($caminhosFotos as $caminho) {
            Storage::disk('local')->delete($caminho);
            Storage::disk('public')->delete($caminho);
        }

        $this->info('Dados eliminados.');

        return self::SUCCESS;
    }
}
