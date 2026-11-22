<?php

namespace Tests\Support;

use Modules\Core\Models\Empresa;
use Modules\Core\Support\Servico;
use Modules\Faturacao\Models\Cliente;

/**
 * Preparação comum dos testes de concorrência real que emitem documentos fiscais: chave privada
 * temporária, empresa e cliente comprometidos na BD (os outros processos têm de os ver) e as
 * variáveis de ambiente que os processos filhos precisam (não herdam o config() do teste).
 * Usar com ProcessosConcorrentes.
 */
trait PreparaEmpresaConcorrente
{
    protected Empresa $empresa;

    protected Cliente $cliente;

    protected string $tmp;

    protected function prepararEmpresaFiscal(string $prefixo): void
    {
        $this->tmp = sys_get_temp_dir()."/{$prefixo}-".uniqid();
        mkdir($this->tmp);
        $this->beforeApplicationDestroyed(function () {
            foreach (glob($this->tmp.'/*') ?: [] as $ficheiro) {
                @unlink($ficheiro);
            }
            @rmdir($this->tmp);
        });

        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $pem);
        file_put_contents($this->tmp.'/chave.pem', $pem);
        config(['faturacao.chave_privada_path' => $this->tmp.'/chave.pem', 'faturacao.agt.ativo' => false]);

        $this->empresa = Empresa::create([
            'nome_comercial' => 'Concorrência '.$prefixo.' '.uniqid(),
            'nif' => (string) random_int(800000000, 899999999),
        ]);
        $this->empresa->aderirServicos([Servico::Faturacao]);
        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Cliente Concorrente', 'nif' => null]);
    }

    /** @return array<string, string> */
    protected function ambienteFiscal(): array
    {
        return [
            'FATURACAO_CHAVE_PRIVADA_PATH' => $this->tmp.'/chave.pem',
            'FATURACAO_AGT_ATIVO' => 'false',
        ];
    }

    /** @param  array<int, array<string, mixed>>  $resultados */
    protected function aceites(array $resultados): int
    {
        return count(array_filter($resultados, fn ($r) => $r['ok']));
    }

    /** Todas as recusas têm de ser regras de negócio (nunca deadlock nem erro técnico). */
    protected function assertRecusasDeNegocio(array $resultados, string $classe, string $fragmento): void
    {
        foreach ($resultados as $r) {
            if ($r['ok']) {
                continue;
            }

            $this->assertStringContainsString($classe, $r['erro'], 'Falha técnica em vez de recusa de negócio: '.$r['erro']);
            $this->assertStringContainsString($fragmento, $r['erro'], 'Recusa por outro motivo: '.$r['erro']);
        }
    }
}
