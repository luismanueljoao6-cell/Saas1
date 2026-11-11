<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('atelier_campanha_envios', function (Blueprint $table): void {
            $table->string('estado', 20)->default('pendente')->change();
        });
    }

    public function down(): void
    {
        // Não reduzir o domínio de estados: envios já aceites poderiam
        // impedir o rollback ou perder informação operacional.
    }
};
