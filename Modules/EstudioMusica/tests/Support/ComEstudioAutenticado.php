<?php

namespace Modules\EstudioMusica\Tests\Support;

use Modules\Core\Models\User;
use Modules\Core\Services\RegistoEmpresaService;
use Modules\Core\Services\TenantManager;

/**
 * Empresa nova com o serviço Estúdio ativo + o Administrador que a registou
 * (papéis, permissões e subscrição de ensaio já provisionados), pronto para
 * actingAs(). Os módulos não têm autoload-dev: os testes fazem require_once
 * deste ficheiro.
 */
trait ComEstudioAutenticado
{
    protected function registarEstudio(string $nif): User
    {
        $this->withoutVite();

        return app(RegistoEmpresaService::class)->registar(
            ['nome_comercial' => 'Estúdio '.$nif, 'nif' => $nif],
            ['name' => 'Admin '.$nif, 'email' => "admin{$nif}@example.test", 'password' => 'Senha-segura-123'],
            ['estudio'],
        );
    }

    /** Pedidos HTTP limpam o tenant no fim: volta a defini-lo antes de ler/escrever modelos no teste. */
    protected function noTenantDe(User $utilizador): void
    {
        app(TenantManager::class)->set((int) $utilizador->empresa_id);
    }
}
