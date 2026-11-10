<?php

namespace Modules\Core\Tests\Unit;

use LogicException;
use Modules\Core\Models\NotificationOutbox;
use Modules\Core\Notifications\Contracts\NotificationChannel;
use Modules\Core\Notifications\DTO\NotificationSendResult;
use Modules\Core\Notifications\NotificationChannelRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class NotificationChannelRegistryLazyTest extends TestCase
{
    public function test_canal_preguicoso_so_e_construido_no_primeiro_uso(): void
    {
        $construcoes = 0;
        $registry = new NotificationChannelRegistry;
        $registry->registerLazy('lazy', function () use (&$construcoes): NotificationChannel {
            $construcoes++;

            return $this->canal('lazy');
        });

        $this->assertTrue($registry->has('lazy'));
        $this->assertSame(0, $construcoes, 'Registar não pode construir o canal (resolveria o fornecedor configurado).');

        $this->assertSame($registry->get('lazy'), $registry->get('lazy'));
        $this->assertSame(1, $construcoes);
    }

    public function test_erro_de_configuracao_num_canal_preguicoso_so_surge_quando_e_usado(): void
    {
        $registry = new NotificationChannelRegistry;
        $registry->registerLazy('mal-configurado', function (): NotificationChannel {
            throw new RuntimeException('Canal "xpto" não está registado.');
        });

        $this->assertTrue($registry->has('mal-configurado'));
        $this->expectException(RuntimeException::class);

        $registry->get('mal-configurado');
    }

    public function test_canal_preguicoso_com_identificador_diferente_do_registado_e_recusado(): void
    {
        $registry = new NotificationChannelRegistry;
        $registry->registerLazy('esperado', fn (): NotificationChannel => $this->canal('outro'));

        $this->expectException(LogicException::class);

        $registry->get('esperado');
    }

    private function canal(string $identificador): NotificationChannel
    {
        return new class($identificador) implements NotificationChannel
        {
            public function __construct(private readonly string $identificador) {}

            public function identifier(): string
            {
                return $this->identificador;
            }

            public function send(NotificationOutbox $notification): NotificationSendResult
            {
                return NotificationSendResult::accepted($this->identificador);
            }
        };
    }
}
