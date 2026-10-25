<?php

namespace Modules\Faturacao\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\AgtSubmissao;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\Produto;

/**
 * Correção de uma fatura que a AGT marcou como inválida ou rejeitada.
 *
 * A AGT não aceita reenviar o mesmo documentNo (regra E46): a correção é um
 * documento NOVO — número novo na série — enviado com documentStatus "C" e com
 * rejectedDocumentNo = número do documento rejeitado (o AgtDocumentoBuilder
 * trata disso a partir de `faturas.rejeitado_documento_no`).
 *
 * Este serviço só cria o RASCUNHO da correção (cliente e linhas copiados da fatura
 * rejeitada, que continua intacta na cadeia fiscal). Quem o cria revê-o e emite-o pelo
 * fluxo normal. Notas de crédito, notas de débito e recibos rejeitados não são
 * corrigidos aqui: os totais já creditados/recebidos dependem de decisão humana.
 */
class AgtCorrecaoService
{
    public function __construct(protected FaturaService $faturas) {}

    /**
     * Idempotente: se a correção já foi criada, devolve-a.
     *
     * @throws DomainException
     */
    public function criarRascunhoDeCorrecao(AgtSubmissao $submissao): Fatura
    {
        if (! in_array($submissao->estado, [AgtSubmissao::INVALIDO, AgtSubmissao::REJEITADO], true)) {
            throw new DomainException('Só se corrigem documentos que a AGT marcou como inválidos ou rejeitados; falhas técnicas resolvem-se com o reenvio.');
        }

        if ($submissao->documento_type !== Fatura::class) {
            throw new DomainException('A criação automática de correções só existe para faturas. Notas de crédito, notas de débito e recibos rejeitados exigem tratamento manual (consulta os erros devolvidos pela AGT).');
        }

        return DB::transaction(function () use ($submissao): Fatura {
            // Serializa pedidos simultâneos de correção do mesmo documento.
            $atual = AgtSubmissao::query()->lockForUpdate()->findOrFail($submissao->id);
            $empresaId = (int) $atual->empresa_id;

            $existente = Fatura::withoutGlobalScopes()
                ->where('empresa_id', $empresaId)
                ->where('rejeitado_documento_no', $atual->documento_no)
                ->first();

            if ($existente) {
                return $existente;
            }

            $original = Fatura::withoutGlobalScopes()
                ->where('empresa_id', $empresaId)
                ->with('linhas')
                ->findOrFail($atual->documento_id);

            $empresa = Empresa::query()->findOrFail($empresaId);
            $cliente = Cliente::withoutGlobalScopes()->where('empresa_id', $empresaId)->findOrFail($original->cliente_id);

            // Um produto entretanto apagado não impede a correção: a linha segue sem ligação ao produto.
            $produtosAtivos = Produto::withoutGlobalScopes()
                ->where('empresa_id', $empresaId)
                ->whereNull('deleted_at')
                ->whereIn('id', $original->linhas->pluck('produto_id')->filter()->all())
                ->pluck('id')
                ->all();

            $linhas = $original->linhas->map(fn ($linha) => [
                'produto_id' => in_array($linha->produto_id, $produtosAtivos, true) ? $linha->produto_id : null,
                'descricao' => $linha->descricao,
                'quantidade' => $linha->quantidade,
                'preco_unitario' => $linha->preco_unitario,
                'taxa_iva' => $linha->taxa_iva,
                'motivo_isencao' => $linha->motivo_isencao,
            ])->all();

            $rascunho = $this->faturas->criarRascunho($empresa, $cliente, $linhas, $original->observacoes);
            $rascunho->update(['rejeitado_documento_no' => $atual->documento_no]);

            Log::warning('Rascunho de correção criado para documento rejeitado pela AGT', [
                'submissao_id' => $atual->id,
                'documento_rejeitado' => $atual->documento_no,
                'rascunho_id' => $rascunho->id,
                'estado_agt' => $atual->estado,
                'erros_agt' => $atual->erros,
            ]);

            return $rascunho->fresh('linhas');
        });
    }
}
