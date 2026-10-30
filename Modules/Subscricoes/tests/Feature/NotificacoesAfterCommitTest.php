<?php

namespace Modules\Subscricoes\Tests\Feature;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Subscricoes\Notifications\PagamentoConfirmadoNotification;
use Modules\Subscricoes\Notifications\RenovacaoProximaNotification;
use Tests\TestCase;

/**
 * O trait Queueable do Laravel já declara $afterCommit: redeclarar a propriedade com outro tipo ou
 * valor na notificação é erro FATAL ao carregar a classe (e mata o processo, incluindo o worker).
 */
class NotificacoesAfterCommitTest extends TestCase
{
    public function test_as_notificacoes_carregam_e_so_seguem_depois_do_commit(): void
    {
        $pagamento = new PagamentoConfirmadoNotification('1000.00', 'AOA', '2026-12-31');
        $renovacao = new RenovacaoProximaNotification('2026-12-31', 'REF123', '1000.00', 'AOA', '2026-12-25');

        $this->assertInstanceOf(ShouldQueue::class, $pagamento);
        $this->assertInstanceOf(ShouldQueue::class, $renovacao);
        $this->assertTrue($pagamento->afterCommit);
        $this->assertTrue($renovacao->afterCommit);
    }

    public function test_os_emails_continuam_a_ser_gerados(): void
    {
        $destinatario = (object) ['name' => 'Ana'];

        $mail = (new PagamentoConfirmadoNotification('1000.00', 'AOA', '2026-12-31'))->toMail($destinatario);
        $this->assertSame('Pagamento confirmado — subscrição ativa', $mail->subject);

        $mail = (new RenovacaoProximaNotification('2026-12-31', 'REF123', '1000.00', 'AOA', '2026-12-25'))->toMail($destinatario);
        $this->assertSame('A tua subscrição termina em breve', $mail->subject);
    }
}
