<?php

namespace App\Services;

/** Provider positively rejected parameters before endpoint execution, not an unknown outcome. */
class PaymentGatewayRejected extends \RuntimeException {}
