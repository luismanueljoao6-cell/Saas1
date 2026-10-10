<?php

namespace Modules\Core\Support;

/**
 * Serviços que uma empresa pode contratar. Cada caso corresponde a um
 * módulo de negócio: as rotas desse módulo ficam protegidas pelo
 * middleware 'servico:<valor>' e o menu/painel só o mostram a quem aderiu.
 *
 * Para acrescentar um módulo vertical novo: um caso aqui + 'servico:<valor>'
 * nas rotas dele. Nada mais no Core muda.
 */
enum Servico: string
{
    case Faturacao = 'faturacao';
    case Atelier = 'atelier';
    case Estudio = 'estudio';

    public function rotulo(): string
    {
        return match ($this) {
            self::Faturacao => 'Faturação',
            self::Atelier => 'Atelier de Costura',
            self::Estudio => 'Estúdio de Música',
        };
    }

    public function descricao(): string
    {
        return match ($this) {
            self::Faturacao => 'Faturas, notas de crédito e débito, recibos, clientes, produtos e exportação SAF-T.',
            self::Atelier => 'Pedidos, medidas dos clientes, provas, equipa, portfólio público e campanhas.',
            self::Estudio => 'Salas, sessões, projetos musicais, versões de áudio e portal do cliente para aprovação.',
        };
    }

    /** Nome da rota de entrada do serviço (cartão do painel). */
    public function rotaInicial(): string
    {
        return match ($this) {
            self::Faturacao => 'faturacao.faturas.index',
            self::Atelier => 'atelier.pedidos.index',
            self::Estudio => 'estudiomusica.projetos.index',
        };
    }

    /**
     * Serviços que este traz consigo. Atelier e Estúdio emitem recibos e
     * faturas internamente, por isso incluem sempre a Faturação.
     *
     * @return array<int, self>
     */
    public function dependencias(): array
    {
        return match ($this) {
            self::Faturacao => [],
            self::Atelier, self::Estudio => [self::Faturacao],
        };
    }

    public function nota(): ?string
    {
        return $this->dependencias() === []
            ? null
            : 'Inclui a Faturação, necessária para emitir recibos e faturas.';
    }

    /** @return array<int, string> */
    public static function valores(): array
    {
        return array_map(fn (self $servico) => $servico->value, self::cases());
    }

    /**
     * Normaliza a lista (aceita enums ou strings, ignora valores
     * desconhecidos) e acrescenta as dependências.
     *
     * @param  array<int, self|string>  $servicos
     * @return array<int, self>
     */
    public static function comDependencias(array $servicos): array
    {
        $resultado = [];

        foreach ($servicos as $servico) {
            $servico = $servico instanceof self ? $servico : self::tryFrom((string) $servico);

            if ($servico === null) {
                continue;
            }

            foreach ([...$servico->dependencias(), $servico] as $incluido) {
                $resultado[$incluido->value] = $incluido;
            }
        }

        return array_values($resultado);
    }
}
