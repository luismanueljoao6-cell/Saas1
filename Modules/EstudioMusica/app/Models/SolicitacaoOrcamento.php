<?php

namespace Modules\EstudioMusica\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Traits\BelongsToTenant;

/**
 * Lead do formulário público da landing page (secção G). Deliberadamente
 * não usa BelongsToTenant da forma habitual para o CREATE — o formulário é
 * público (sem utilizador autenticado, logo sem tenant identificado), por
 * isso o controller define empresa_id explicitamente a partir da empresa
 * dona da landing page, não do TenantManager. Ver LandingController.
 */
class SolicitacaoOrcamento extends Model
{
    use BelongsToTenant;

    protected $table = 'solicitacoes_orcamento';

    protected $fillable = [
        'empresa_id',
        'nome',
        'email',
        'telefone',
        'tipo_servico_desejado',
        'data_preferida',
        'mensagem',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'data_preferida' => 'date',
        ];
    }
}
