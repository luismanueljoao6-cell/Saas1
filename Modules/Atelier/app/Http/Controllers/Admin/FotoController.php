<?php

namespace Modules\Atelier\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Modules\Atelier\Models\Pedido;
use Modules\Atelier\Models\PedidoFoto;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serve as fotos dos pedidos (dados pessoais) só a utilizadores
 * autenticados da própria empresa. PedidoFoto não tem tenant próprio, por
 * isso o isolamento vem do Pedido (TenantScope fail-closed).
 */
class FotoController extends Controller
{
    public function mostrar(Request $request, int $foto): StreamedResponse
    {
        $foto = PedidoFoto::findOrFail($foto);
        $pedido = Pedido::findOrFail($foto->pedido_id);
        $utilizador = $request->user();

        abort_unless(
            $utilizador->hasAnyRole(['Administrador', 'Secretária'])
                || ($utilizador->hasRole('Costureira') && (int) $pedido->responsavel_id === (int) $utilizador->id),
            403
        );

        $disco = Storage::disk(PedidoFoto::DISCO)->exists($foto->caminho)
            ? PedidoFoto::DISCO
            : PedidoFoto::DISCO_LEGADO;

        abort_unless(Storage::disk($disco)->exists($foto->caminho), 404);

        return Storage::disk($disco)->response($foto->caminho, null, [
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
