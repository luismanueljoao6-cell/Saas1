<?php

namespace Modules\Faturacao\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Exceptions\AgtException;
use Modules\Faturacao\Models\Serie;
use Modules\Faturacao\Support\Jws;

/**
 * Pede à AGT a criação (ou extensão) de uma série de numeração de facturas
 * electrónicas e guarda o código e o intervalo autorizados. O documentNo
 * passa a ser "{tipo} {seriesCode}/{n}", dentro do intervalo autorizado.
 */
class AgtSerieService
{
    public function __construct(
        protected AgtClient $cliente,
        protected AgtAssinaturaService $assinaturas,
        protected TenantManager $tenantManager,
    ) {}

    public function solicitar(
        Empresa $empresa,
        string $tipoDocumento,
        ?int $ano = null,
        ?string $estabelecimento = null,
        string $contingencia = 'N',
    ): Serie {
        if (! array_key_exists($tipoDocumento, (array) config('faturacao.tipos_documento'))) {
            throw new InvalidArgumentException("Tipo de documento '{$tipoDocumento}' não suportado.");
        }

        if (! in_array($contingencia, ['N', 'C'], true)) {
            throw new InvalidArgumentException("Indicador de contingência '{$contingencia}' inválido (N ou C).");
        }

        $nif = trim((string) $empresa->nif);

        if ($nif === '') {
            throw new InvalidArgumentException('A empresa não tem NIF configurado.');
        }

        $ano ??= now()->year;
        $estabelecimento = trim((string) $estabelecimento) !== ''
            ? trim((string) $estabelecimento)
            : (string) config('faturacao.agt.estabelecimento_padrao', 'SEDE');

        // Objecto assinado em jwsSignature, na ordem indicada pela AGT.
        $assinado = [
            'taxRegistrationNumber' => $nif,
            'seriesYear' => $ano,
            'documentType' => $tipoDocumento,
            'establishmentNumber' => $estabelecimento,
            'seriesContingencyIndicator' => $contingencia,
        ];

        $corpo = Jws::canonico([
            'schemaVersion' => '2.0',
            'submissionUUID' => (string) Str::uuid(),
            'taxRegistrationNumber' => $nif,
            'submissionTimeStamp' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'softwareInfo' => $this->assinaturas->softwareInfo(),
            'seriesYear' => $ano,
            'documentType' => $tipoDocumento,
            'establishmentNumber' => $estabelecimento,
            'jwsSignature' => $this->assinaturas->assinarRequisicao((int) $empresa->id, $assinado),
            'seriesContingencyIndicator' => $contingencia,
        ]);

        $resposta = $this->cliente->solicitarSerie($corpo);
        $resultado = $resposta['corpo']['seriesFEResult'] ?? null;

        if ($resposta['http'] !== 200 || ! is_array($resultado) || empty($resultado['seriesCode'])) {
            $erros = AgtClient::normalizarErros($resposta['corpo']['errorList'] ?? []);

            throw $erros !== []
                ? AgtException::rejeitado($erros)
                : AgtException::respostaInesperada($resposta['http']);
        }

        return $this->guardar($empresa, $tipoDocumento, $ano, $estabelecimento, $contingencia, $resultado);
    }

    /** @param  array<string, mixed>  $resultado  seriesFEResult */
    protected function guardar(Empresa $empresa, string $tipoDocumento, int $ano, string $estabelecimento, string $contingencia, array $resultado): Serie
    {
        $codigo = (string) $resultado['seriesCode'];
        $primeiro = $this->numeroDe($resultado['firstDocumentNo'] ?? null);
        $ultimo = $this->numeroDe($resultado['lastDocumentNo'] ?? null);
        $quantidade = $this->numeroDe($resultado['authorizedQuantity'] ?? null, obrigatorio: false) ?? max(0, $ultimo - $primeiro + 1);

        return $this->tenantManager->semTenant(fn () => DB::transaction(function () use ($empresa, $tipoDocumento, $ano, $estabelecimento, $contingencia, $codigo, $primeiro, $ultimo, $quantidade) {
            $serie = Serie::query()
                ->where('empresa_id', $empresa->id)
                ->where('agt_codigo', $codigo)
                ->lockForUpdate()
                ->first();

            if ($serie) {
                // Extensão de uma série existente: a numeração continua, só cresce o limite.
                $serie->update([
                    'quantidade_autorizada' => $quantidade,
                    'ultimo_numero_autorizado' => max((int) $serie->ultimo_numero_autorizado, $ultimo),
                ]);

                return $serie;
            }

            return Serie::create([
                'empresa_id' => $empresa->id,
                'tipo_documento' => $tipoDocumento,
                'ano' => $ano,
                'prefixo' => $codigo,
                'agt_codigo' => $codigo,
                'estabelecimento' => $estabelecimento,
                'contingencia' => $contingencia,
                'quantidade_autorizada' => $quantidade,
                // A primeira factura emitida terá o número "primeiro".
                'ultimo_numero' => max(0, $primeiro - 1),
                'ultimo_numero_autorizado' => $ultimo,
                'agt_criada_em' => now(),
            ]);
        }));
    }

    /** Aceita "1000" ou um identificador completo terminado em número ("FT X/1000"). */
    protected function numeroDe(mixed $valor, bool $obrigatorio = true): ?int
    {
        if (is_int($valor)) {
            return $valor;
        }

        if (is_string($valor) && preg_match('/(\d+)$/', trim($valor), $m) === 1) {
            return (int) $m[1];
        }

        if ($obrigatorio) {
            throw AgtException::configuracaoInvalida('a AGT devolveu um intervalo de numeração inválido para a série.');
        }

        return null;
    }
}
