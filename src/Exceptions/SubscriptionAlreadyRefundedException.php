<?php

namespace RiseTechApps\ApiKey\Exceptions;

use RuntimeException;

/**
 * O pagamento da assinatura já foi devolvido.
 *
 * Lançada antes de qualquer chamada ao gateway, então quem a recebe sabe que
 * nenhum dinheiro se moveu nesta tentativa.
 */
class SubscriptionAlreadyRefundedException extends RuntimeException {}
