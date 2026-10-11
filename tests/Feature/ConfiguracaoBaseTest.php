<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Modules\Core\Support\ProxiesConfiaveis;
use Tests\TestCase;

/**
 * Regressões da auditoria de configuração: variáveis de ambiente VAZIAS
 * (como as do .env.example) não podem anular os valores por omissão.
 */
class ConfiguracaoBaseTest extends TestCase
{
    /** Avalia um ficheiro de config com variáveis de ambiente forçadas. */
    protected function configCom(string $ficheiro, array $variaveis): array
    {
        $guardado = [];

        foreach ($variaveis as $chave => $valor) {
            $guardado[$chave] = [$_ENV[$chave] ?? null, $_SERVER[$chave] ?? null];

            if ($valor === null) {
                unset($_ENV[$chave], $_SERVER[$chave]);
            } else {
                $_ENV[$chave] = $_SERVER[$chave] = $valor;
            }
        }

        try {
            return require base_path($ficheiro);
        } finally {
            foreach ($guardado as $chave => [$env, $server]) {
                $env === null ? $_ENV[$chave] = null : $_ENV[$chave] = $env;
                $server === null ? $_SERVER[$chave] = null : $_SERVER[$chave] = $server;

                if ($env === null) {
                    unset($_ENV[$chave]);
                }
                if ($server === null) {
                    unset($_SERVER[$chave]);
                }
            }
        }
    }

    public function test_chave_privada_vazia_no_env_usa_o_caminho_por_omissao(): void
    {
        $config = $this->configCom('Modules/Faturacao/config/config.php', ['FATURACAO_CHAVE_PRIVADA_PATH' => '']);

        $this->assertSame(storage_path('app/faturacao/chave-privada.pem'), $config['chave_privada_path']);
    }

    public function test_iva_isento_nao_e_permitido_por_omissao(): void
    {
        $vazio = $this->configCom('Modules/Faturacao/config/config.php', ['FATURACAO_TAXAS_IVA_PERMITIDAS' => '']);
        $ausente = $this->configCom('Modules/Faturacao/config/config.php', ['FATURACAO_TAXAS_IVA_PERMITIDAS' => null]);

        $this->assertSame([14.0], $vazio['taxas_iva_permitidas']);
        $this->assertSame([14.0], $ausente['taxas_iva_permitidas']);
    }

    public function test_env_example_nao_define_variaveis_criticas_em_branco(): void
    {
        $exemplo = file_get_contents(base_path('.env.example'));

        $this->assertDoesNotMatchRegularExpression('/^FATURACAO_CHAVE_PRIVADA_PATH=\s*$/m', $exemplo);
        $this->assertDoesNotMatchRegularExpression('/^TRUSTED_PROXIES=\s*$/m', $exemplo);
        $this->assertMatchesRegularExpression('/^FATURACAO_TAXAS_IVA_PERMITIDAS=14\s*$/m', $exemplo);
    }

    public function test_fuso_horario_vem_do_env_e_por_omissao_e_luanda(): void
    {
        $sem = $this->configCom('config/app.php', ['APP_TIMEZONE' => null]);
        $com = $this->configCom('config/app.php', ['APP_TIMEZONE' => 'Africa/Lagos']);

        $this->assertSame('Africa/Luanda', $sem['timezone']);
        $this->assertSame('Africa/Lagos', $com['timezone']);
    }

    public function test_a_hora_de_luanda_decide_o_ano_da_serie(): void
    {
        // 23:30 UTC de 31/12 já é 00:30 de 01/01 em Luanda (UTC+1).
        Carbon::setTestNow(Carbon::parse('2026-12-31 23:30:00', 'UTC'));

        try {
            $this->assertSame('Africa/Luanda', config('app.timezone'));
            $this->assertSame(2027, now()->year);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_validacao_em_portugues(): void
    {
        $this->app->setLocale('pt');

        $mensagem = validator(['nif' => ''], ['nif' => 'required'])->errors()->first('nif');

        $this->assertSame('O campo NIF é obrigatório.', $mensagem);
    }

    public function test_proxies_confiaveis(): void
    {
        // Vazio fora do Codespaces: ninguém.
        $this->assertNull(ProxiesConfiaveis::resolver('', false));
        $this->assertNull(ProxiesConfiaveis::resolver(null, false));
        // O bug original: '' não era tratado como "não definido" no Codespaces.
        $this->assertSame('*', ProxiesConfiaveis::resolver('', true));
        $this->assertSame('*', ProxiesConfiaveis::resolver(null, true));
        // Valor explícito ganha sempre ao Codespaces.
        $this->assertSame(['10.0.0.1', '10.0.0.2'], ProxiesConfiaveis::resolver('10.0.0.1, 10.0.0.2', true));
        $this->assertSame('*', ProxiesConfiaveis::resolver('*', false));
        $this->assertNull(ProxiesConfiaveis::resolver(' , ', false));
    }

    public function test_modulos_exigem_a_mesma_versao_minima_de_php_que_o_projeto(): void
    {
        $raiz = json_decode(file_get_contents(base_path('composer.json')), true)['require']['php'];

        foreach (glob(base_path('Modules/*/composer.json')) as $ficheiro) {
            $modulo = json_decode(file_get_contents($ficheiro), true);

            $this->assertSame($raiz, $modulo['require']['php'], $ficheiro);
        }
    }

    public function test_repositorio_nao_contem_gitlinks_orfaos(): void
    {
        $saida = shell_exec('git -C '.escapeshellarg(base_path()).' ls-files -s 2>/dev/null') ?? '';

        $this->assertStringNotContainsString('160000 ', $saida, 'Existe um submódulo/gitlink sem .gitmodules.');
    }
}
