<?php

namespace Modules\Atelier\Exceptions;

/**
 * O pedido pode já ter chegado ao fornecedor, mas a resposta perdeu-se.
 * É subclasse de NotificacaoException para que os fluxos legados, que só
 * conhecem a exceção base, continuem a tratá-la como falha de envio.
 */
class NotificacaoResultadoIndeterminadoException extends NotificacaoException {}
