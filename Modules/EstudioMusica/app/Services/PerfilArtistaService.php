<?php

namespace Modules\EstudioMusica\Services;

use Illuminate\Support\Str;
use Modules\Faturacao\Models\Cliente;

/**
 * "Ficha do Artista" (secção A): os campos vivem na tabela `clientes` (ver
 * a migration 2026_04_01_000001), mas Modules\Faturacao\Models\Cliente não
 * foi alterado — nem o $fillable nem os $casts sabem destes campos, de
 * propósito, para o módulo Faturação continuar sem qualquer conhecimento
 * do EstudioMusica. Este serviço é o ÚNICO sítio do módulo que lê/escreve
 * estes campos: forceFill() ignora $fillable (funciona mesmo sem o
 * Cliente do Faturacao saber destes atributos) e aqui tratamos à mão a
 * codificação/descodificação JSON que um cast normalmente faria.
 */
class PerfilArtistaService
{
    /**
     * @param  array{nome_artistico?: ?string, genero_musical?: ?string, integrantes_banda?: ?array<int, string>, links_redes_sociais?: ?array<string, string>, data_nascimento?: ?string}  $dados
     */
    public function atualizar(Cliente $cliente, array $dados): Cliente
    {
        $cliente->forceFill([
            'nome_artistico' => $dados['nome_artistico'] ?? $cliente->nome_artistico,
            'genero_musical' => $dados['genero_musical'] ?? $cliente->genero_musical,
            'integrantes_banda' => array_key_exists('integrantes_banda', $dados)
                ? json_encode(array_values($dados['integrantes_banda'] ?? []))
                : $cliente->getRawOriginal('integrantes_banda'),
            'links_redes_sociais' => array_key_exists('links_redes_sociais', $dados)
                ? json_encode($dados['links_redes_sociais'] ?? [])
                : $cliente->getRawOriginal('links_redes_sociais'),
            'data_nascimento' => $dados['data_nascimento'] ?? $cliente->data_nascimento,
        ])->save();

        return $cliente->refresh();
    }

    /**
     * @return array{nome_artistico: ?string, genero_musical: ?string, integrantes_banda: array<int, string>, links_redes_sociais: array<string, string>, data_nascimento: ?string}
     */
    public function obter(Cliente $cliente): array
    {
        return [
            'nome_artistico' => $cliente->nome_artistico,
            'genero_musical' => $cliente->genero_musical,
            'integrantes_banda' => $this->descodificar($cliente->getRawOriginal('integrantes_banda')),
            'links_redes_sociais' => $this->descodificar($cliente->getRawOriginal('links_redes_sociais')),
            'data_nascimento' => $cliente->data_nascimento,
        ];
    }

    /**
     * Gera (ou substitui) o token de acesso temporário ao Portal do
     * Cliente — ver Http\Middleware\VerificarTokenPortalCliente. A
     * Receção pode chamar isto de novo a qualquer momento para "reenviar
     * o link" com um token novo, invalidando o anterior.
     */
    public function gerarTokenPortal(Cliente $cliente): string
    {
        $token = Str::random(48);

        $cliente->forceFill([
            'token_portal' => $token,
            'token_portal_expira_em' => now()->addDays((int) config('estudiomusica.portal_token_validade_dias')),
        ])->save();

        return $token;
    }

    protected function descodificar(?string $json): array
    {
        if (! $json) {
            return [];
        }

        return json_decode($json, true) ?: [];
    }
}
