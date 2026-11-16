<?php

// Ficheiro de configuração do módulo Core.
// Após a instalação, publica este ficheiro para config/core.php caso precises
// de o sobrepor sem editar o módulo diretamente.

return [

    /*
    |--------------------------------------------------------------------------
    | Nome do módulo
    |--------------------------------------------------------------------------
    */
    'name' => 'Core',

    /*
    |--------------------------------------------------------------------------
    | Período de tolerância (Grace Period)
    |--------------------------------------------------------------------------
    |
    | Número de dias após a expiração da subscrição durante os quais a empresa
    | ainda consegue aceder aos dados para consulta, mas fica impedida de
    | emitir novas faturas (ver Empresa::podeEmitirFaturas()). Recomendado
    | entre 3 e 5 dias.
    |
    */
    'periodo_tolerancia_dias' => (int) env('CORE_PERIODO_TOLERANCIA_DIAS', 5),

    /*
    |--------------------------------------------------------------------------
    | Duração do período experimental (trial)
    |--------------------------------------------------------------------------
    |
    | Dias de acesso completo a partir do registo. Terminado o trial, a rotina
    | diária (módulo Subscrições) põe a empresa em período de tolerância e,
    | depois, bloqueia-a. 0 = trial sem fim (comportamento antigo).
    |
    */
    'trial_dias' => (int) env('CORE_TRIAL_DIAS', 14),

    /*
    |--------------------------------------------------------------------------
    | Super administradores
    |--------------------------------------------------------------------------
    |
    | Lista de e-mails (separados por vírgula) que, ao registar-se, são
    | marcados como super admin (is_super_admin = true) e ficam isentos da
    | verificação de tenant e de subscrição. Usa isto com moderação — o ideal
    | é promover manualmente via seeder/tinker em produção, não por env.
    |
    */
    'super_admin_emails' => array_filter(array_map(
        'trim',
        explode(',', (string) env('CORE_SUPER_ADMIN_EMAILS', ''))
    )),

    /*
    |--------------------------------------------------------------------------
    | Guard de autenticação usado pelo módulo
    |--------------------------------------------------------------------------
    */
    'guard' => env('CORE_AUTH_GUARD', 'web'),

    /*
    |--------------------------------------------------------------------------
    | Multi-tenancy — escritas sem contexto de empresa
    |--------------------------------------------------------------------------
    |
    | O que fazer quando um model com BelongsToTenant é criado, atualizado ou
    | eliminado sem tenant identificado E fora de um bypass explícito
    | (TenantManager::semTenant()):
    |
    |   permitir — comportamento antigo (silencioso). Por omissão nos testes.
    |   avisar   — regista um aviso com o model e a operação. Por omissão fora
    |              de testes: serve para descobrir fluxos que escrevem sem contexto.
    |   bloquear — lança LogicException (fail-closed). Usar em produção depois
    |              de uma fase em 'avisar' sem avisos legítimos nos logs.
    |
    */
    'tenant' => [
        'escritas_sem_contexto' => env(
            'CORE_TENANT_ESCRITAS_SEM_CONTEXTO',
            env('APP_ENV') === 'testing' ? 'permitir' : 'avisar'
        ),
    ],

];
