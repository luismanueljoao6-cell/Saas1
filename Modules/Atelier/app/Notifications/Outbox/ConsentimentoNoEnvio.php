<?php

namespace Modules\Atelier\Notifications\Outbox;

use Modules\Atelier\Services\CrmService;
use Modules\Core\Models\NotificationOutbox;
use Modules\Core\Notifications\DTO\NotificationSendResult;
use Modules\Core\Services\TenantManager;

/**
 * O consentimento é verificado quando o envio é criado, mas a Outbox pode
 * enviar bem mais tarde (retry, backoff, requeue manual). Por isso é
 * confirmado outra vez no momento efetivo do envio (Lei n.º 22/11).
 *
 * Só se aplica a mensagens com finalidade «marketing». Falha fechada: uma
 * mensagem de marketing sem cliente identificado nunca é enviada.
 */
final class ConsentimentoNoEnvio
{
    public const CODIGO = 'consent_revoked';

    public function __construct(
        private readonly CrmService $crm,
        private readonly TenantManager $tenants,
    ) {}

    public function recusaSe(NotificationOutbox $notificacao): ?NotificationSendResult
    {
        $payload = (array) $notificacao->payload;

        if (($payload['finalidade'] ?? null) !== 'marketing') {
            return null;
        }

        $clienteId = (int) ($payload['cliente_id'] ?? 0);
        $empresaId = (int) $notificacao->empresa_id;

        // O canal corre em workers sem tenant ativo; a consulta filtra
        // explicitamente pela empresa da Outbox.
        $temConsentimento = $clienteId > 0
            && $this->tenants->semTenant(fn () => $this->crm->temConsentimentoMarketing($empresaId, $clienteId));

        if ($temConsentimento) {
            return null;
        }

        return NotificationSendResult::rejected(
            'atelier',
            'Consentimento de marketing revogado ou inexistente no momento do envio.',
            self::CODIGO,
        );
    }
}
