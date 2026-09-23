<?php

namespace Kicol\FullFlow\Exceptions;

/**
 * Pagamento com cartão indisponível no momento (503 `cartao_indisponivel`):
 * não há provedor de cartão ativo no FullFlow. O SaaS mantém boleto/Pix.
 */
class CardUnavailableException extends CardException {}
