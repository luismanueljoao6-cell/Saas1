<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('armazem_inventarios', function (Blueprint $table): void {
            $table->foreignId('submetido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submetido_em')->nullable();
            $table->foreignId('rejeitado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejeitado_em')->nullable();
            $table->text('motivo_rejeicao')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('armazem_inventarios', function (Blueprint $table): void {
            $table->dropForeign(['submetido_por']);
            $table->dropForeign(['rejeitado_por']);
            $table->dropColumn([
                'submetido_por',
                'submetido_em',
                'rejeitado_por',
                'rejeitado_em',
                'motivo_rejeicao',
            ]);
        });
    }
};
