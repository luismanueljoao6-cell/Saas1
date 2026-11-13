<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compras', function (Blueprint $table) {
            $table->decimal('total_pago', 18, 2)->default(0)->after('total');
            $table->string('estado_pagamento', 20)->default('pendente')->after('total_pago');
        });

        Schema::create('compra_pagamentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('compra_id')->constrained('compras')->cascadeOnDelete();
            $table->decimal('valor', 18, 2);
            $table->date('data_pagamento');
            $table->string('metodo', 30);
            $table->string('referencia', 100)->nullable();
            $table->text('observacoes')->nullable();
            $table->foreignId('registado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['empresa_id', 'compra_id', 'data_pagamento']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compra_pagamentos');

        Schema::table('compras', function (Blueprint $table) {
            $table->dropColumn(['total_pago', 'estado_pagamento']);
        });
    }
};
