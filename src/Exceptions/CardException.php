<?php

namespace Kicol\FullFlow\Exceptions;

/**
 * Cartão de crédito (v0.10): a API recusou abrir o formulário, cadastrar,
 * trocar ou remover um cartão. `$codigo` é o código do envelope de erro do
 * FullFlow, para o SaaS traduzir em mensagem ao lojista:
 *
 *  - assinatura_sem_plano_de_cobranca — assinatura ainda fora do motor de cobrança
 *  - sem_cobranca_em_aberto           — nada a pagar agora
 *  - cobranca_nao_elegivel            — a cobrança em aberto não aceita cartão neste momento
 *  - assinatura_sem_cliente           — cadastro incompleto
 *  - cartao_nao_encontrado            — cartão não pertence ao cliente
 *  - ultimo_cartao                    — ver LastCardException
 *  - cartao_indisponivel              — ver CardUnavailableException (503)
 */
class CardException extends FullFlowException
{
    public function __construct(string $message = '', public readonly string $codigo = '')
    {
        parent::__construct($message);
    }
}
