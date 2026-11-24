<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * O grupo 'web' leva o AuthenticateSession: a sessão guarda o hash da
     * palavra-passe do utilizador autenticado e desautentica quem não
     * corresponde. Num teste a sessão é partilhada entre pedidos, por isso
     * trocar de utilizador a meio (actingAs($a) e depois actingAs($b)) deixaria
     * o B com o hash do A e ele seria desautenticado. Num navegador real cada
     * utilizador tem a sua sessão: aqui reproduz-se isso, limpando a sessão só
     * quando o utilizador muda.
     */
    public function actingAs(UserContract $user, $guard = null)
    {
        $atual = $this->app['auth']->guard($guard)->user();

        if ($atual !== null && (string) $atual->getAuthIdentifier() !== (string) $user->getAuthIdentifier()) {
            $this->flushSession();
        }

        return parent::actingAs($user, $guard);
    }
}
