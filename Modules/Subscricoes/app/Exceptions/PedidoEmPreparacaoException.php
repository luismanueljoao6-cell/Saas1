<?php

namespace Modules\Subscricoes\Exceptions;

use RuntimeException;

/**
 * Já há um pedido de pagamento a ser criado para esta empresa/subscrição
 * (o gateway ainda está a responder). A mensagem é escrita para o utilizador.
 * Estende RuntimeException para não quebrar quem já apanha RuntimeException.
 */
class PedidoEmPreparacaoException extends RuntimeException {}
