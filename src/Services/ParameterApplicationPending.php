<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Services;

/** Señala que una reserva abierta o incierta impide reenviar la línea al maestro. */
final class ParameterApplicationPending extends \LogicException {}
