<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compra_pagamentos', function (Blueprint $table): void {
            $table->string('chave_idempotencia', 36)->nullable()->after('compra_id');
            $table->char('payload_hash', 64)->nullable()->after('chave_idempotencia');
            $table->unique(
                ['empresa_id', 'chave_idempotencia'],
                'compra_pagamentos_empresa_idem_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('compra_pagamentos', function (Blueprint $table): void {
            $table->dropUnique('compra_pagamentos_empresa_idem_unique');
            $table->dropColumn(['chave_idempotencia', 'payload_hash']);
        });
    }
};
