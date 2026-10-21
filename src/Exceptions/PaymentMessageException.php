<?php

namespace RiseTechApps\ApiKey\Exceptions;

/**
 * Falha de pagamento com mensagem escrita para o cliente (cartão recusado na
 * validação, falta CPF no perfil, PIX fora do limite).
 *
 * É a única exceção cujo texto chega à tela. Qualquer outra falha de gateway
 * (HTTP 500, resposta inesperada) aparece como mensagem genérica, e o detalhe
 * técnico fica só no log.
 */
class PaymentMessageException extends \RuntimeException {}
