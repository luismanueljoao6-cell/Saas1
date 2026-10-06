<?php

namespace Modules\EstudioMusica\Notifications\Channels\Contracts;

/**
 * Contrato comum a qualquer canal de envio de mensagem de texto para um
 * destino (número de telefone). Ver CanalWhatsAppInterface e
 * CanalSmsInterface — dois contratos distintos (não intermutáveis) que
 * partilham esta forma, para poderes injetar "o canal de WhatsApp" e "o
 * canal de SMS" ao mesmo tempo em NotificacaoEstudioService sem
 * ambiguidade no container (o Laravel resolve bindings por tipo de
 * parâmetro, não por nome).
 */
interface CanalMensagemInterface
{
    public function identificador(): string;

    /**
     * @return bool true se o canal aceitou enviar (não garante entrega —
     *              só LogChannel, usado em desenvolvimento, é síncrono e
     *              100% garantido; os canais reais dependem do fornecedor).
     */
    public function enviar(string $destino, string $mensagem): bool;
}
