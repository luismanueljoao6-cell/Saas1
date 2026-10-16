<?php

namespace Modules\EstudioMusica\Services;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\EstudioMusica\Models\AceiteTermos;
use Modules\EstudioMusica\Models\TermosEstudio;
use Modules\Faturacao\Models\Cliente;

/**
 * Termos de Serviço versionados e prova do aceite pelo cliente (portal).
 * Corre com o tenant já definido (staff autenticado ou portal por token).
 *
 * O texto-modelo cobre os pontos que costumam dar conflito num estúdio —
 * direitos de autor e propriedade dos masters (Lei n.º 15/14), pagamento,
 * entrega, cancelamentos, dados pessoais — mas é um PONTO DE PARTIDA: o
 * estúdio deve validá-lo com o seu advogado antes de o publicar/adaptar.
 */
class TermosEstudioService
{
    /** Versão em vigor; cria a versão 1 (texto-modelo) se ainda não houver nenhuma. */
    public function atual(int $empresaId, string $nomeEstudio = 'o Estúdio'): TermosEstudio
    {
        $atual = TermosEstudio::query()->where('empresa_id', $empresaId)->orderByDesc('versao')->first();

        if ($atual) {
            return $atual;
        }

        try {
            return $this->publicar($empresaId, 'Termos de Serviço', $this->textoModelo($nomeEstudio));
        } catch (QueryException) {
            // Dois pedidos em simultâneo tentaram criar a versão 1: ganha o primeiro.
            return TermosEstudio::query()->where('empresa_id', $empresaId)->orderByDesc('versao')->firstOrFail();
        }
    }

    public function publicar(int $empresaId, string $titulo, string $conteudo): TermosEstudio
    {
        return DB::transaction(function () use ($empresaId, $titulo, $conteudo) {
            $proxima = (int) TermosEstudio::query()->where('empresa_id', $empresaId)->max('versao') + 1;

            return TermosEstudio::create([
                'empresa_id' => $empresaId,
                'versao' => $proxima,
                'titulo' => $titulo,
                'conteudo' => $conteudo,
                'publicado_em' => now(),
            ]);
        });
    }

    public function aceitou(Cliente $cliente, TermosEstudio $termos): bool
    {
        return AceiteTermos::query()
            ->where('cliente_id', $cliente->id)
            ->where('termos_estudio_id', $termos->id)
            ->exists();
    }

    /** Idempotente: aceitar duas vezes a mesma versão não duplica o registo. */
    public function registarAceite(Cliente $cliente, TermosEstudio $termos, Request $request): AceiteTermos
    {
        return AceiteTermos::query()->firstOrCreate(
            ['cliente_id' => $cliente->id, 'termos_estudio_id' => $termos->id],
            [
                'empresa_id' => $termos->empresa_id,
                'aceite_em' => now(),
                'ip_hash' => $request->ip() ? hash('sha256', $request->ip().config('app.key')) : null,
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
            ],
        );
    }

    public function textoModelo(string $nomeEstudio): string
    {
        return <<<TEXTO
        1. Objeto
        Estes termos regulam a prestação de serviços de gravação, mistura, masterização, produção e serviços conexos por {$nomeEstudio} ("o Estúdio") ao artista ou cliente ("o Cliente").

        2. Direitos de autor e conexos
        As composições, letras, arranjos e interpretações pertencem aos respetivos autores e artistas e não são transferidos para o Estúdio. O Cliente declara ser titular, ou ter autorização, de todo o material que entrega ao Estúdio (incluindo beats, samples e instrumentais de terceiros) e responde perante terceiros por esse material. Salvo acordo escrito em contrário, depois do pagamento integral o Cliente fica com os direitos de utilização dos ficheiros finais entregues (masters). O Estúdio não utiliza trabalhos do Cliente em portfólio, redes sociais ou publicidade sem autorização expressa.

        3. Preços e pagamento
        Os preços constam da proposta ou da tabela em vigor e não incluem IVA, que é acrescentado à taxa legal. Nos projetos por pacote pode ser exigido um sinal, e o saldo é faturado no final. O Estúdio emite fatura e recibo dos valores recebidos.

        4. Entrega e pré-visualização
        Enquanto o projeto não estiver integralmente pago, o Cliente tem acesso apenas a pré-visualizações em qualidade reduzida. Os ficheiros originais em qualidade total são disponibilizados depois do pagamento integral.

        5. Aprovações e ajustes
        O Cliente ouve cada versão no portal, comenta e aprova ou pede ajustes. Uma versão aprovada considera-se aceite; pedidos adicionais podem ser orçamentados à parte.

        6. Marcação, reagendamento e cancelamento
        O Cliente deve avisar com antecedência razoável se não puder comparecer. O Estúdio pode cobrar as horas reservadas e não utilizadas, nos termos acordados com o Cliente.

        7. Conservação de ficheiros
        O Estúdio conserva os ficheiros do projeto por um período razoável após a entrega. O Cliente deve guardar uma cópia própria dos ficheiros que lhe forem entregues.

        8. Proteção de dados pessoais
        O tratamento dos dados pessoais do Cliente é feito nos termos da Política de Privacidade do Estúdio e da Lei n.º 22/11, de 17 de junho.

        9. Reclamações
        O Estúdio dispõe de Livro de Reclamações no seu estabelecimento e de um canal de reclamações na sua página.

        10. Lei aplicável
        Estes termos regem-se pela lei angolana.
        TEXTO;
    }
}
