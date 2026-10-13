<?php

namespace Modules\Faturacao\Support;

use InvalidArgumentException;
use OpenSSLAsymmetricKey;

/**
 * JSON Web Signature (compact) com RS256 (RSA + SHA-256), como exige a API de
 * Facturação Electrónica da AGT: cabeçalho {"alg":"RS256","typ":"JWT"},
 * payload = objecto JSON completo (não campos concatenados), Base64URL sem
 * padding e chave RSA com pelo menos 2048 bits.
 *
 * Classe sem dependências do framework, para poder ser testada isoladamente.
 */
final class Jws
{
    public const CABECALHO = '{"alg":"RS256","typ":"JWT"}';

    public static function base64url(string $binario): string
    {
        return rtrim(strtr(base64_encode($binario), '+/', '-_'), '=');
    }

    public static function deBase64url(string $texto): ?string
    {
        if ($texto === '' || preg_match('/^[A-Za-z0-9_-]+$/', $texto) !== 1) {
            return null;
        }

        $decodificado = base64_decode(strtr($texto, '-_', '+/'), true);

        return $decodificado === false ? null : $decodificado;
    }

    /**
     * JSON canónico: sem espaços nem quebras de linha, aspas duplas, números
     * sem formatação adicional e a ordem das chaves exactamente como recebida.
     *
     * @param  array<string|int, mixed>  $dados
     */
    public static function canonico(array $dados): string
    {
        return self::codificar($dados);
    }

    /** Assina um payload JSON (já canónico) e devolve o JWS compacto. */
    public static function assinar(string $payloadJson, OpenSSLAsymmetricKey $chavePrivada): string
    {
        $entrada = self::base64url(self::CABECALHO).'.'.self::base64url($payloadJson);
        $assinatura = '';

        if (! openssl_sign($entrada, $assinatura, $chavePrivada, OPENSSL_ALGO_SHA256)) {
            throw new InvalidArgumentException('Falha OpenSSL ao assinar: '.(openssl_error_string() ?: 'erro desconhecido'));
        }

        return $entrada.'.'.self::base64url($assinatura);
    }

    /** Verifica a assinatura RS256 de um JWS compacto. */
    public static function verificar(string $jws, OpenSSLAsymmetricKey $chavePublica): bool
    {
        $partes = explode('.', $jws);

        if (count($partes) !== 3) {
            return false;
        }

        if (self::deBase64url($partes[0]) !== self::CABECALHO) {
            return false;
        }

        $assinatura = self::deBase64url($partes[2]);

        if ($assinatura === null) {
            return false;
        }

        return openssl_verify($partes[0].'.'.$partes[1], $assinatura, $chavePublica, OPENSSL_ALGO_SHA256) === 1;
    }

    /** Devolve o payload (JSON) de um JWS compacto, sem validar a assinatura. */
    public static function payload(string $jws): ?string
    {
        $partes = explode('.', $jws);

        return count($partes) === 3 ? self::deBase64url($partes[1]) : null;
    }

    private static function codificar(mixed $valor): string
    {
        return match (true) {
            $valor instanceof NumeroJson => $valor->literal,
            is_array($valor) => self::codificarArray($valor),
            is_int($valor) => (string) $valor,
            is_bool($valor) => $valor ? 'true' : 'false',
            $valor === null => 'null',
            is_float($valor) => throw new InvalidArgumentException('Usa NumeroJson para valores decimais (nunca float).'),
            default => json_encode((string) $valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        };
    }

    /** @param  array<string|int, mixed>  $dados */
    private static function codificarArray(array $dados): string
    {
        if (array_is_list($dados)) {
            return '['.implode(',', array_map(self::codificar(...), $dados)).']';
        }

        $itens = [];
        foreach ($dados as $chave => $valor) {
            $itens[] = json_encode((string) $chave, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).':'.self::codificar($valor);
        }

        return '{'.implode(',', $itens).'}';
    }
}
