<?php

namespace Modules\Faturacao\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\FaturaLinha;
use Modules\Faturacao\Models\Produto;
use Modules\Faturacao\Support\Dinheiro;
use Modules\Faturacao\Support\GuardaEmissao;
use Throwable;

class FaturaService
{
    public function __construct(
        protected NumeracaoService $numeracaoService,
        protected AssinaturaFiscalService $assinaturaFiscalService,
    ) {}

    /**
     * @param  array<int, array{produto_id?: int, descricao: string, quantidade: float, preco_unitario: float, taxa_iva: float}>  $linhas
     */
    public function criarRascunho(Empresa $empresa, Cliente $cliente, array $linhas, ?string $observacoes = null): Fatura
    {
        if (empty($linhas)) {
            throw new \InvalidArgumentException('Uma fatura precisa de pelo menos uma linha.');
        }

        if ((int) $cliente->empresa_id !== (int) $empresa->id) {
            throw new \DomainException('O cliente não pertence a esta empresa.');
        }

        $this->validarLinhasFiscais((int) $empresa->id, $linhas);

        return DB::transaction(function () use ($empresa, $cliente, $linhas, $observacoes) {
            $fatura = Fatura::create([
                'empresa_id' => $empresa->id,
                'cliente_id' => $cliente->id,
                // Série do ano corrente, só como referência do tipo: a série
                // DEFINITIVA (e o número) são atribuídos em emitir().
                'serie_id' => $this->numeracaoService->obterOuCriarSerie($empresa->id, 'FT')->id,
                'estado' => 'rascunho',
                'moeda' => 'AOA',
                'observacoes' => $observacoes,
            ]);

            $this->substituirLinhas($fatura, $linhas);
            $this->recalcularTotais($fatura);

            return $fatura->fresh('linhas');
        });
    }

    /**
     * Atribui número sequencial, encadeia o hash, assina (RSA) e marca como
     * emitida. Idempotente: emitir duas vezes devolve a mesma fatura sem
     * consumir um segundo número.
     *
     * @throws Throwable
     */
    public function emitir(Fatura $fatura): Fatura
    {
        try {
            return DB::transaction(function () use ($fatura) {
                // Lock explícito por empresa, SEM depender do tenant ambiente
                // (jobs, comandos e testes não passam pelo middleware).
                $fatura = Fatura::withoutGlobalScopes()
                    ->where('empresa_id', $fatura->empresa_id)
                    ->lockForUpdate()
                    ->findOrFail($fatura->id);

                if ($fatura->estaEmitido()) {
                    return $fatura->fresh('linhas');
                }

                GuardaEmissao::garantir((int) $fatura->empresa_id);

                if ($fatura->linhas()->doesntExist()) {
                    throw new \DomainException('Não é possível emitir uma fatura sem linhas.');
                }

                // O valor assinado tem de bater exatamente com as linhas.
                $this->recalcularTotais($fatura);

                $resultado = $this->numeracaoService->proximoNumero($fatura->empresa_id, 'FT');

                $hashAnterior = $this->numeracaoService->hashDoDocumentoAnterior(
                    Fatura::class,
                    $resultado['serie']->id,
                    $resultado['numero_sequencial'],
                );

                $fatura->serie_id = $resultado['serie']->id;

                $this->assinaturaFiscalService->assinarEEmitir(
                    $fatura,
                    $resultado['numero_sequencial'],
                    $resultado['numero_documento'],
                    now(),
                    $hashAnterior,
                );

                $fatura->save();

                Log::info('Fatura emitida', [
                    'fatura_id' => $fatura->id,
                    'numero_documento' => $fatura->numero_documento,
                    'empresa_id' => $fatura->empresa_id,
                ]);

                return $fatura->fresh('linhas');
            }, attempts: 5);
        } catch (Throwable $e) {
            Log::error('Falha ao emitir fatura', [
                'fatura_id' => $fatura->id,
                'erro' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * @param  array<int, array{produto_id?: int, descricao: string, quantidade: float, preco_unitario: float, taxa_iva: float}>  $linhas
     */
    protected function substituirLinhas(Fatura $fatura, array $linhas): void
    {
        $fatura->linhas()->delete();

        foreach (array_values($linhas) as $indice => $dadosLinha) {
            $linha = new FaturaLinha([
                'fatura_id' => $fatura->id,
                'produto_id' => $dadosLinha['produto_id'] ?? null,
                'descricao' => $dadosLinha['descricao'],
                'quantidade' => $dadosLinha['quantidade'],
                'preco_unitario' => $dadosLinha['preco_unitario'],
                'taxa_iva' => $dadosLinha['taxa_iva'],
                'codigo_isencao_iva' => $dadosLinha['codigo_isencao_iva'] ?? null,
                'motivo_isencao_iva' => $dadosLinha['motivo_isencao_iva'] ?? null,
                'ordem' => $indice,
            ]);

            $linha->calcularValores()->save();
        }
    }

    protected function validarLinhasFiscais(int $empresaId, array $linhas): void
    {
        $permitidas = array_map('floatval', (array) config('faturacao.taxas_iva_permitidas', []));
        foreach ($linhas as $indice => $linha) {
            $taxa = (float) ($linha['taxa_iva'] ?? -1);
            if (! in_array($taxa, $permitidas, true)) {
                throw new \InvalidArgumentException('A taxa de IVA da linha '.($indice + 1).' não está autorizada na configuração fiscal.');
            }
            if ((float) ($linha['quantidade'] ?? 0) <= 0 || (float) ($linha['preco_unitario'] ?? -1) < 0) {
                throw new \InvalidArgumentException('Quantidade e preço da linha '.($indice + 1).' são inválidos.');
            }
            if (! empty($linha['produto_id'])) {
                $produto = Produto::withoutGlobalScopes()->where('empresa_id', $empresaId)->where('ativo', true)->find($linha['produto_id']);
                if (! $produto) throw new \DomainException('O produto da linha '.($indice + 1).' não existe, não está ativo ou pertence a outra empresa.');
                if ($produto->taxa_iva !== null && (float) $produto->taxa_iva !== $taxa) {
                    throw new \DomainException('A taxa de IVA da linha '.($indice + 1).' não corresponde à taxa configurada no produto.');
                }
            }
            if ($taxa == 0.0) {
                $codigo = trim((string) ($linha['codigo_isencao_iva'] ?? ''));
                $motivo = trim((string) ($linha['motivo_isencao_iva'] ?? ''));
                if (! preg_match('/^M[0-9]{2}$/', $codigo) || mb_strlen($motivo) < 6 || mb_strlen($motivo) > 60) {
                    throw new \InvalidArgumentException('A linha '.($indice + 1).' com IVA 0% exige código de isenção Mnn e motivo legal entre 6 e 60 caracteres.');
                }
            }
        }
    }

    protected function recalcularTotais(Fatura $fatura): void
    {
        $linhas = $fatura->linhas()->get();

        $soma = fn (string $campo): string => Dinheiro::formatar(
            (int) $linhas->sum(fn ($l) => Dinheiro::centimos($l->{$campo}))
        );

        $fatura->update([
            'valor_sem_iva' => $soma('valor_sem_iva'),
            'valor_iva' => $soma('valor_iva'),
            'valor_total' => $soma('valor_total'),
        ]);
    }
}
