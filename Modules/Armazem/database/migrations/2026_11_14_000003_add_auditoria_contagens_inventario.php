<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('armazem_inventario_linhas', function (Blueprint $table): void {
            $table->foreignId('contado_por')->nullable()->after('diferenca')->constrained('users')->nullOnDelete();
            $table->timestamp('contado_em')->nullable()->after('contado_por');
            $table->index(['empresa_id', 'contado_por'], 'armazem_inventario_linhas_empresa_contador_idx');
        });
    }

    public function down(): void
    {
        Schema::table('armazem_inventario_linhas', function (Blueprint $table): void {
            $table->dropIndex('armazem_inventario_linhas_empresa_contador_idx');
            $table->dropConstrainedForeignId('contado_por');
            $table->dropColumn('contado_em');
        });
    }
};
