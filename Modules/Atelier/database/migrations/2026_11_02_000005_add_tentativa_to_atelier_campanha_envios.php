<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('atelier_campanha_envios', function (Blueprint $table): void {
            $table->unsignedInteger('tentativa')->default(1)->after('estado');
        });
    }

    public function down(): void
    {
        Schema::table('atelier_campanha_envios', function (Blueprint $table): void {
            $table->dropColumn('tentativa');
        });
    }
};
