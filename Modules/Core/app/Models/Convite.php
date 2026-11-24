<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Traits\BelongsToTenant;

class Convite extends Model
{
    use BelongsToTenant;

    protected $table = 'convites';

    protected $fillable = [
        'empresa_id',
        'email',
        'name',
        'administrador',
        'token_hash',
        'convidado_por',
        'expires_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'administrador' => 'boolean',
            'expires_at' => 'datetime',
            'aceite_em' => 'datetime',
            'revogado_em' => 'datetime',
        ];
    }

    /** Nem aceite, nem revogado, nem expirado. */
    public function scopePendentes(Builder $query): Builder
    {
        return $query->whereNull('aceite_em')
            ->whereNull('revogado_em')
            ->where('expires_at', '>', now());
    }

    public function convidadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'convidado_por');
    }
}
