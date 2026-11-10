<?php

namespace Modules\EstudioMusica\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Modules\EstudioMusica\Jobs\GerarPreviewAudioJob;
use Modules\EstudioMusica\Models\FaixaMusical;
use Modules\EstudioMusica\Models\VersaoAudio;
use Modules\EstudioMusica\Services\NotificacaoEstudioService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * "Upload de arquivos de áudio/demos para o portal" (secção H — papel do
 * Engenheiro de Som). Ficheiros guardados no disco 'local' (privado, NÃO
 * public) de propósito: a audição em si é livre para o cliente dono do
 * projeto (ver PortalClienteController::reproduzir), mas o DOWNLOAD só é
 * permitido depois de o projeto estar financeiramente quitado — isso só é
 * possível servindo o ficheiro sempre através de uma rota autenticada/com
 * token, nunca por uma URL pública direta.
 *
 * Os ids de faixa/versão chegam como int, não como model por binding
 * implícito — ver comentário em SalaEstudioController::update().
 */
class VersaoAudioController extends Controller
{
    public function store(Request $request, int $faixa, NotificacaoEstudioService $notificacao): RedirectResponse
    {
        $faixa = FaixaMusical::findOrFail($faixa);

        $dados = $request->validate([
            'rotulo' => ['required', 'string', 'max:255'],
            // mimetypes (tipo detetado pelo CONTEÚDO) em vez de mimes/extensions (extensão do
            // nome): o ficheiro é depois servido inline com o tipo que o conteúdo tem, por
            // isso não pode ser, por exemplo, um HTML com extensão .mp3.
            'ficheiro' => ['required', 'file', 'max:51200', 'mimetypes:audio/mpeg,audio/mp3,audio/wav,audio/x-wav,audio/wave,audio/mp4,audio/x-m4a,audio/aac,audio/ogg,audio/flac,audio/x-flac'],
        ]);

        // Uma pasta por empresa: separa os masters de cada estúdio no disco
        // (backups, auditoria, eventual eliminação a pedido do titular).
        $caminho = $request->file('ficheiro')->store("estudiomusica/versoes-audio/{$faixa->empresa_id}", 'local');

        try {
            $versao = $faixa->versoes()->create([
                'empresa_id' => $faixa->empresa_id,
                'rotulo' => $dados['rotulo'],
                'caminho_ficheiro' => $caminho,
                'estado' => 'pendente_aprovacao',
                'enviada_em' => now(),
            ]);
        } catch (Throwable $e) {
            // Sem registo na base de dados o ficheiro ficaria órfão no disco.
            Storage::disk('local')->delete($caminho);

            throw $e;
        }

        // A pré-visualização (ffmpeg) gera-se já, em segundo plano — antes só nascia no primeiro
        // "play" do cliente, dentro do pedido HTTP dele (lento, e com vários pedidos Range em paralelo).
        GerarPreviewAudioJob::dispatch($versao->id, (int) $versao->empresa_id);

        $notificacao->novaDemoDisponivel($versao);

        return back()->with('sucesso', 'Nova versão enviada.');
    }

    /**
     * Audição interna (staff) — sem gate financeiro, a equipa tem sempre
     * acesso ao que ela própria produziu.
     */
    public function reproduzir(int $versao): BinaryFileResponse
    {
        $versao = VersaoAudio::findOrFail($versao);

        // Ficheiro em falta no disco: 404 limpo (response()->file() daria 500).
        abort_unless(Storage::disk('local')->exists($versao->caminho_ficheiro), 404, 'O ficheiro desta versão não está no servidor.');

        // BinaryFileResponse (não Storage::response) por causa dos pedidos
        // HTTP Range do <audio> — ver PortalClienteController::reproduzir().
        return response()->file(Storage::disk('local')->path($versao->caminho_ficheiro));
    }

    /**
     * "Aviso de Aprovação Pendente" (secção D) — o requisito não dá uma
     * regra de tempo para disparar isto sozinho, por isso fica como um
     * lembrete que a Receção envia manualmente ao artista quando achar
     * necessário, em vez de inventarmos um prazo (ex.: "3 dias depois")
     * que não foi pedido.
     */
    public function lembrarAprovacao(int $versao, NotificacaoEstudioService $notificacao): RedirectResponse
    {
        $versao = VersaoAudio::findOrFail($versao);

        // Só faz sentido lembrar a versão mais recente que ainda aguarda decisão — não uma
        // versão antiga, nem uma já aprovada (ou com ajustes pedidos).
        if ($versao->estado !== 'pendente_aprovacao' || $versao->faixaMusical->ultimaVersao()?->id !== $versao->id) {
            return back()->with('erro', 'Esta versão já não está à espera de aprovação do artista.');
        }

        $notificacao->aprovacaoPendente($versao);

        return back()->with('sucesso', 'Lembrete enviado ao artista.');
    }
}
