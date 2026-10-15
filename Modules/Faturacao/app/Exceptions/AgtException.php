<?php

namespace Modules\Faturacao\Exceptions;

use Exception;

/**
 * Erros na comunicação com a AGT (Facturação Electrónica) e na gestão das
 * séries autorizadas. `$erros` normaliza a errorList devolvida pela AGT.
 */
class AgtException extends Exception
{
    /** @param  array<int, array{codigo: string, descricao: string}>  $erros */
    public function __construct(string $message, public readonly array $erros = [])
    {
        parent::__construct($message);
    }

    public static function semCredenciais(): self
    {
        return new self('Credenciais da AGT em falta: define FATURACAO_AGT_UTILIZADOR e FATURACAO_AGT_PALAVRA_PASSE (pedido por e-mail a produtores.dfe.dcrr.agt@minfin.gov.ao).');
    }

    public static function configuracaoInvalida(string $motivo): self
    {
        return new self('Configuração AGT inválida: '.$motivo);
    }

    public static function transporte(string $motivo): self
    {
        return new self('Falha de comunicação com a AGT: '.$motivo);
    }

    public static function respostaInesperada(int $http): self
    {
        return new self("Resposta inesperada da AGT (HTTP {$http}).");
    }

    /** @param  array<int, array{codigo: string, descricao: string}>  $erros */
    public static function rejeitado(array $erros): self
    {
        $texto = implode('; ', array_map(fn (array $e) => "{$e['codigo']}: {$e['descricao']}", $erros));

        return new self('A AGT rejeitou o pedido — '.$texto, $erros);
    }

    public static function serieIndisponivel(string $tipoDocumento, int $ano): self
    {
        return new self("Não existe série autorizada pela AGT para {$tipoDocumento}/{$ano}. Pede-a com: php artisan faturacao:agt-solicitar-serie <empresa> {$tipoDocumento}");
    }

    public static function serieEsgotada(string $codigo, int $ultimoAutorizado): self
    {
        return new self("A série AGT {$codigo} esgotou a numeração autorizada (até {$ultimoAutorizado}). Pede uma extensão da série à AGT.");
    }
}
