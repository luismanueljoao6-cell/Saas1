<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    /**
     * Os estados de processamento são extensíveis e não cabem no enum
     * histórico (rascunho/agendada/enviada/cancelada).
     *
     * A conversão é incremental para instalações que já executaram a
     * migration original. Mantemos os valores existentes sem os reescrever.
     */
    public function up(): void
    {
        Schema::table('atelier_campanhas', function (Blueprint $table): void {
            $table->string('estado', 30)->default('rascunho')->change();
        });
    }

    public function down(): void
    {
        // Não converter de volta para enum: campanhas em processamento ou
        // concluídas com falhas perderiam estados válidos no rollback.
    }
};
