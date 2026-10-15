<?php

namespace Modules\EstudioMusica\Notifications\Channels;

use Illuminate\Support\Facades\Log;
use Modules\EstudioMusica\Notifications\Channels\Contracts\CanalSmsInterface;
use Modules\EstudioMusica\Notifications\Channels\Contracts\CanalWhatsAppInterface;
use Modules\EstudioMusica\Support\NumeroTelefone;

/**
 * Canal por omissão (config('estudiomusica.canal_whatsapp'/'canal_sms') =
 * 'log'): não contacta nenhum fornecedor externo, só regista a mensagem
 * que teria sido enviada. Seguro para desenvolvimento e para este
 * ambiente sem acesso à rede — troca para os canais reais só quando
 * tiveres credenciais válidas.
 */
class LogChannel implements CanalSmsInterface, CanalWhatsAppInterface
{
    public function identificador(): string
    {
        return 'log';
    }

    public function enviar(string $destino, string $mensagem): bool
    {
        // Tokens de portal são credenciais bearer: nunca os persistir nos logs.
        $mensagemSemTokens = preg_replace(
            ['~(/portal/)[A-Za-z0-9]+~', '~([?&]token=)[^&\\s]+~i'],
            ['$1[TOKEN_REDACTED]', '$1[TOKEN_REDACTED]'],
            $mensagem,
        ) ?? '[mensagem não disponível]';

        Log::info('[EstudioMusica] Mensagem (canal log, não enviada de verdade)', [
            'destino' => NumeroTelefone::mascarar($destino),
            'mensagem' => $mensagemSemTokens,
        ]);

        return true;
    }
}
