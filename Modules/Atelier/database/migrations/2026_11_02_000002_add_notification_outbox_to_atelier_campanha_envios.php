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
            $table->foreignId('notification_outbox_id')->nullable();
            $table->unique('notification_outbox_id', 'atelier_campanha_envio_outbox_unique');
            $table->foreign('notification_outbox_id', 'atelier_campanha_envio_outbox_fk')
                ->references('id')
                ->on('notification_outbox')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('atelier_campanha_envios', function (Blueprint $table): void {
            $table->dropForeign('atelier_campanha_envio_outbox_fk');
            $table->dropUnique('atelier_campanha_envio_outbox_unique');
            $table->dropColumn('notification_outbox_id');
        });
    }
};
