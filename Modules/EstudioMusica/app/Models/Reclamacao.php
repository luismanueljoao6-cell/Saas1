<?php

namespace Modules\EstudioMusica\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Traits\BelongsToTenant;

class Reclamacao extends Model
{
    use BelongsToTenant;

    protected $table = 'reclamacoes_estudio';

    protected $fillable = [
        'empresa_id', 'nome', 'email', 'telefone', 'assunto', 'descricao',
        'estado', 'resposta', 'respondida_em', 'consentimento_dados_em',
    ];

    protected function casts(): array
    {
        return [
            'respondida_em' => 'datetime',
            'consentimento_dados_em' => 'datetime',
        ];
    }
}
