<?php

namespace Modules\EstudioMusica\Tests\Support;

use Modules\Core\Models\User;
use Modules\Core\Services\TenantManager;
use Modules\EstudioMusica\Models\FaixaMusical;
use Modules\EstudioMusica\Models\ProjetoMusical;
use Modules\EstudioMusica\Models\Reclamacao;
use Modules\EstudioMusica\Models\SalaEstudio;
use Modules\EstudioMusica\Models\SessaoEstudio;
use Modules\EstudioMusica\Models\SolicitacaoOrcamento;
use Modules\EstudioMusica\Models\VersaoAudio;
use Modules\Faturacao\Models\Cliente;

/**
 * Cria um estúdio completo — Administrador + um exemplar de cada recurso —
 * para os testes de isolamento entre empresas. Todos os nomes levam a $marca
 * (ex.: «SEGREDO-A»), para os testes poderem procurar essa marca numa página
 * e provar que dados de uma empresa não aparecem noutra.
 *
 * Usa em conjunto com ComEstudioAutenticado (registarEstudio).
 */
trait CriaEstudioComDados
{
    /**
     * @return array{admin: User, cliente: Cliente, sala: SalaEstudio, projeto: ProjetoMusical, faixa: FaixaMusical, versao: VersaoAudio, sessao: SessaoEstudio, solicitacao: SolicitacaoOrcamento, reclamacao: Reclamacao}
     */
    protected function criarEstudioComDados(string $nif, string $marca): array
    {
        $admin = $this->registarEstudio($nif);
        $empresaId = (int) $admin->empresa_id;

        // Os modelos levam empresa_id explícito e os testes leem sem scope:
        // nada depende de um tenant que sobrou de outro passo.
        app(TenantManager::class)->clear();

        $cliente = Cliente::create(['empresa_id' => $empresaId, 'nome' => "Artista {$marca}"]);
        $sala = SalaEstudio::create(['empresa_id' => $empresaId, 'nome' => "Sala {$marca}", 'preco_hora' => 5000]);

        $projeto = ProjetoMusical::create([
            'empresa_id' => $empresaId,
            'cliente_id' => $cliente->id,
            'nome' => "Projeto {$marca}",
            'tipo_cobranca' => 'hora',
            'percentual_sinal' => 40,
        ]);

        $faixa = FaixaMusical::create([
            'empresa_id' => $empresaId,
            'projeto_musical_id' => $projeto->id,
            'nome' => "Faixa {$marca}",
            'estado' => 'agendado',
            'ordem' => 1,
        ]);

        $versao = VersaoAudio::create([
            'empresa_id' => $empresaId,
            'faixa_musical_id' => $faixa->id,
            'rotulo' => "Versão {$marca}",
            'caminho_ficheiro' => "estudio/{$marca}.mp3",
            'estado' => 'pendente_aprovacao',
            'enviada_em' => now(),
        ]);

        $inicio = now()->addDay()->startOfHour();
        $sessao = new SessaoEstudio;
        $sessao->forceFill([
            'empresa_id' => $empresaId,
            'sala_estudio_id' => $sala->id,
            'cliente_id' => $cliente->id,
            'projeto_musical_id' => $projeto->id,
            'tipo_servico' => 'mixagem',
            'inicio_previsto' => $inicio,
            'fim_previsto' => $inicio->copy()->addHour(),
            'estado' => 'agendada',
        ])->save();

        $solicitacao = SolicitacaoOrcamento::create([
            'empresa_id' => $empresaId,
            'nome' => "Pedido {$marca}",
            'email' => strtolower("pedido-{$marca}@example.test"),
            'tipo_servico_desejado' => 'mixagem',
            'mensagem' => "Mensagem {$marca}",
            'estado' => 'pendente',
        ]);

        $reclamacao = Reclamacao::create([
            'empresa_id' => $empresaId,
            'nome' => "Reclamante {$marca}",
            'email' => strtolower("reclamante-{$marca}@example.test"),
            'assunto' => "Assunto {$marca}",
            'descricao' => "Descrição {$marca}",
            'estado' => 'recebida',
            'consentimento_dados_em' => now(),
        ]);

        return compact('admin', 'cliente', 'sala', 'projeto', 'faixa', 'versao', 'sessao', 'solicitacao', 'reclamacao');
    }
}
