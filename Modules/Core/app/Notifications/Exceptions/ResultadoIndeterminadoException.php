<?php

namespace Modules\Core\Notifications\Exceptions;

use RuntimeException;

/**
 * Lançada por um canal quando o pedido ao fornecedor pode já ter sido recebido
 * mas a resposta não chegou (tempo esgotado depois de enviar, ligação cortada a
 * meio da resposta, exceção depois da aceitação).
 *
 * Qualquer outra exceção continua a significar «falhou antes de o fornecedor
 * aceitar» e segue a política normal de retry. Esta nunca é repetida
 * automaticamente: a notificação fica em «unknown_result» até haver decisão.
 */
class ResultadoIndeterminadoException extends RuntimeException {}
