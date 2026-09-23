<?php

namespace Kicol\FullFlow\Exceptions;

/**
 * Remover o último cartão de quem está em débito automático (409
 * `ultimo_cartao`). É uma pergunta, não um erro sem saída: repetir a remoção
 * com `$confirm = true` remove mesmo assim, e as cobranças voltam a sair por
 * boleto/Pix.
 */
class LastCardException extends CardException {}
