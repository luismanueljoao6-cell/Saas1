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
