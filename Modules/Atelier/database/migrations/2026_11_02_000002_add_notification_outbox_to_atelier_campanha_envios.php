<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notification_outbox')) {
            throw new RuntimeException(
                'A tabela notification_outbox do Core tem de existir antes da associação dos envios de campanhas.'
            );
        }

        Schema::table('atelier_campanha_envios', function (Blueprint $table): void {
            $table->foreignId('notification_outbox_id')
                ->nullable()
                ->after('cliente_id')
                ->unique('atelier_campanha_envio_outbox_unique')
                ->constrained('notification_outbox')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('atelier_campanha_envios', function (Blueprint $table): void {
            $table->dropForeign(['notification_outbox_id']);
            $table->dropUnique('atelier_campanha_envio_outbox_unique');
            $table->dropColumn('notification_outbox_id');
        });
    }
};
