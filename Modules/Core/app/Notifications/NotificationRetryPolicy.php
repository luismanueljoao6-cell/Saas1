<?php

namespace Modules\Core\Notifications;

/**
 * Classifica uma recusa explícita do fornecedor (ou uma falha antes do envio) e
 * decide o que fazer a seguir. Não basta limitar o número de tentativas: um
 * destinatário inválido ou credenciais erradas nunca se resolvem por retry.
 *
 * | Situação                        | Classe       | Tratamento                       |
 * |---------------------------------|--------------|----------------------------------|
 * | Credenciais inválidas / 401/403 | BLOCKED      | failed + alerta, sem retry       |
 * | Transferência APD não autorizada| BLOCKED      | failed + alerta, sem retry       |
 * | Destinatário/payload inválido   | PERMANENT    | failed, sem retry                |
 * | Limite do fornecedor / 429      | RATE_LIMITED | retry com espera mínima/retry_after |
 * | Erro temporário / 5xx / outros  | RETRY        | backoff exponencial              |
 *
 * Timeout e resposta indeterminada não passam por aqui: o canal lança
 * ResultadoIndeterminadoException e a notificação fica em «unknown_result».
 * Uma aceitação confirmada nunca é reenviada (ver NotificationProcessor).
 */
final class NotificationRetryPolicy
{
    public const RETRY = 'retry';

    public const RATE_LIMITED = 'rate_limited';

    public const PERMANENT = 'permanent';

    public const BLOCKED = 'blocked';

    private const BLOCKED_CODES = [
        'invalid_credentials',
        'provider_auth_failed',
        'apd_transfer_not_authorized',
    ];

    private const PERMANENT_CODES = [
        'invalid_recipient',
        'invalid_payload',
        'consent_revoked',
    ];

    private const RATE_LIMITED_CODES = [
        'rate_limited',
        'provider_rate_limit',
    ];

    public static function classify(?string $errorCode, ?int $responseCode = null): string
    {
        if ($errorCode !== null) {
            if (in_array($errorCode, self::BLOCKED_CODES, true)) {
                return self::BLOCKED;
            }

            if (in_array($errorCode, self::PERMANENT_CODES, true)) {
                return self::PERMANENT;
            }

            if (in_array($errorCode, self::RATE_LIMITED_CODES, true)) {
                return self::RATE_LIMITED;
            }
        }

        return match (true) {
            in_array($responseCode, [401, 403], true) => self::BLOCKED,
            $responseCode === 429 => self::RATE_LIMITED,
            in_array($responseCode, [400, 404, 410, 422], true) => self::PERMANENT,
            default => self::RETRY,
        };
    }

    /** Falha que não se resolve repetindo o envio. */
    public static function semRetry(string $classe): bool
    {
        return in_array($classe, [self::PERMANENT, self::BLOCKED], true);
    }

    /**
     * Espera até à próxima tentativa, em segundos. Limite do fornecedor respeita
     * o retry_after indicado (se existir) e nunca espera menos de 60 s.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function atrasoSegundos(string $classe, int $tentativas, array $metadata = []): int
    {
        $backoff = min(3600, 30 * (2 ** max(0, $tentativas - 1)));

        if ($classe !== self::RATE_LIMITED) {
            return $backoff;
        }

        $retryAfter = max(0, (int) ($metadata['retry_after'] ?? 0));

        return min(3600, max($backoff, 60, $retryAfter));
    }
}
