<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();

            $table->string('nome');
            // "Consumidor final" é um cliente sem NIF, válido em várias
            // faturações lusófonas — por isso nullable, não unique global
            // (é unique só dentro da mesma empresa, quando preenchido).
            $table->string('nif')->nullable();
            $table->string('morada')->nullable();
            $table->string('email')->nullable();
            $table->string('telefone')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['empresa_id', 'nif']);
            // Nota: em MySQL, NULL não colide consigo próprio num índice
            // único — por isso vários "consumidor final" (nif nulo) na
            // mesma empresa são permitidos sem violar esta constraint.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};
