<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Esta migration assume que a tabela `users` já existe (instalação
     * padrão do Laravel). Acrescenta as colunas necessárias para o
     * multi-tenancy, para distinguir super admins, para desativação de
     * contas sem apagar dados, e para 2FA (compatível com o formato usado
     * pelo Laravel Fortify / pragmarx/google2fa-laravel).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('empresa_id')
                ->nullable()
                ->after('id')
                ->constrained('empresas')
                ->nullOnDelete()
                ->comment('Nulo apenas para super admins da plataforma');

            $table->boolean('is_super_admin')->default(false)->after('empresa_id');
            $table->boolean('ativo')->default(true)->after('is_super_admin')
                ->comment('Permite desativar um utilizador sem apagar o registo (auditoria)');

            // 2FA — colunas no formato esperado pelo Laravel Fortify.
            // A UI de configuração (QR code, códigos de recuperação) fica
            // para uma iteração seguinte do Core; ver README.
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');

            $table->index(['empresa_id', 'ativo']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('empresa_id');
            $table->dropColumn([
                'is_super_admin',
                'ativo',
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
            ]);
        });
    }
};
