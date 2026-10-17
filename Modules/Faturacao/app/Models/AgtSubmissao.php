<?php

namespace Modules\Faturacao\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Estado do envio de um documento fiscal à AGT. Sem BelongsToTenant de
 * propósito: é lido por comandos/jobs que operam entre empresas e nunca é
 * exposto em pedidos HTTP de utilizadores.
 */
class AgtSubmissao extends Model
{
    public const PENDENTE = 'pendente';

    public const ENVIADO = 'enviado';

    public const VALIDO = 'valido';

    public const INVALIDO = 'invalido';

    public const REJEITADO = 'rejeitado';

    public const ERRO = 'erro';

    public const AGUARDA_INTERVENCAO = 'aguarda_intervencao';

    public const ROTULOS = [
        self::PENDENTE => 'Pendente de envio',
        self::ENVIADO => 'Enviado — a aguardar validação da AGT',
        self::VALIDO => 'Válido (confirmado pela AGT)',
        self::INVALIDO => 'Inválido (recusado na validação da AGT)',
        self::REJEITADO => 'Rejeitado no envio',
        self::ERRO => 'Erro técnico',
        self::AGUARDA_INTERVENCAO => 'A aguardar intervenção',
    ];

    protected $table = 'agt_submissoes';

    protected $fillable = [
        'empresa_id',
        'documento_type',
        'documento_id',
        'documento_no',
        'estado',
        'submission_uuid',
        'request_id',
        'tentativas',
        'erros',
        'enviado_em',
        'ultima_consulta_em',
        'validado_em',
    ];

    public function rotulo(): string
    {
        return self::ROTULOS[$this->estado] ?? (string) $this->estado;
    }

    /** Só "válido" é confirmação fiscal: "enviado" apenas significa que a AGT recebeu o pedido. */
    public function confirmadoFiscalmente(): bool
    {
        return $this->estado === self::VALIDO;
    }

    protected function casts(): array
    {
        return [
            'tentativas' => 'integer',
            'erros' => 'array',
            'enviado_em' => 'datetime',
            'ultima_consulta_em' => 'datetime',
            'validado_em' => 'datetime',
        ];
    }
}
