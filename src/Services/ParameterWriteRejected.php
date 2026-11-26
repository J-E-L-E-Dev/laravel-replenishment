<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Services;

/** Indica que la operación no comenzó o que el adaptador confirmó su reversión. */
final class ParameterWriteRejected extends \RuntimeException {}
