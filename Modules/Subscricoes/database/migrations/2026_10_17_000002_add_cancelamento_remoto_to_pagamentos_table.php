<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pagamentos', function (Blueprint $table) {
            // Referência expirada/cancelada localmente mas ainda viva na ProxyPay:
            // a reconciliação repete o cancelamento até ter sucesso.
            $table->boolean('cancelamento_remoto_pendente')->default(false)->index();
            $table->unsignedSmallInteger('cancelamento_remoto_tentativas')->default(0);
            $table->text('cancelamento_remoto_erro')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pagamentos', function (Blueprint $table) {
            $table->dropIndex(['cancelamento_remoto_pendente']);
            $table->dropColumn([
                'cancelamento_remoto_pendente',
                'cancelamento_remoto_tentativas',
                'cancelamento_remoto_erro',
            ]);
        });
    }
};
