<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Tests;

use Asisnet\Replenishment\DTO\InventoryData;
use Asisnet\Replenishment\DTO\ReservationData;
use Asisnet\Replenishment\Services\AvailabilityCalculator;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Verifica aritmética exacta, identidad de compromisos y propagación de desconocidos sin bases.
 */
final class AvailabilityCalculatorTest extends TestCase
{
    /**
     * Verifica la fórmula por componentes y conserva el sobrecompromiso negativo.
     *
     * @return void
     */
    public function testComponentsAndNegativeAvailabilityAreExact(): void
    {
        $calculator = new AvailabilityCalculator;
        $inventory = new InventoryData('0001', 'global', '10', new DateTimeImmutable('2026-11-07T00:00:00Z'));
        $result = $calculator->calculate('0001', 'global', $inventory, [
            new ReservationData('fixed:1', '0001', 'global', '3', ReservationData::FIXED),
            new ReservationData('manual:1', '0001', 'global', '4', ReservationData::MANUAL),
            new ReservationData('quote:1', '0001', 'global', '5.000001', ReservationData::QUOTATION),
        ]);
        self::assertSame('3.000000', $result->fixedReserved);
        self::assertSame('4.000000', $result->manualReserved);
        self::assertSame('5.000001', $result->quotationReserved);
        self::assertSame('12.000001', $result->totalCommitted);
        self::assertSame('-2.000001', $result->available);
        self::assertSame('0.000000', $result->positiveAvailable);
        self::assertTrue($result->overcommitted);
    }

    /**
     * Un origen sin reservas confirmadas no reduce la existencia física.
     *
     * @return void
     */
    public function testNoReservationsPreservePhysicalStock(): void
    {
        $result = (new AvailabilityCalculator)->calculate('0001', 'global',
            new InventoryData('0001', 'global', '1234567890123456.12', new DateTimeImmutable), []);
        self::assertSame('1234567890123456.120000', $result->available);
        self::assertSame('0.000000', $result->totalCommitted);
        self::assertFalse($result->overcommitted);
    }

    /**
     * Conserva exactitud más allá del rango seguro de enteros representables como float.
     *
     * @return void
     */
    public function testPrecisionIsNotLostDuringSubtraction(): void
    {
        $result = (new AvailabilityCalculator)->calculate('0001', 'global',
            new InventoryData('0001', 'global', '9007199254740993.12', new DateTimeImmutable),
            [new ReservationData('quote:1', '0001', 'global', '0.000001', ReservationData::QUOTATION)]);
        self::assertSame('9007199254740993.119999', $result->available);
    }

    /**
     * Deduplica una identidad repetida sin fusionar reservas distintas de igual cantidad.
     *
     * @return void
     */
    public function testIdentityDeduplicationDoesNotDiscardDifferentLines(): void
    {
        $first = new ReservationData('quote:1', '0001', 'global', '1', ReservationData::QUOTATION);
        $same = new ReservationData('quote:1', '0001', 'global', '1.000000', ReservationData::QUOTATION);
        $second = new ReservationData('quote:2', '0001', 'global', '1', ReservationData::QUOTATION);
        $result = (new AvailabilityCalculator)->calculate('0001', 'global', null, [$first, $same, $second]);
        self::assertSame('2.000000', $result->quotationReserved);
        self::assertNull($result->available);
    }

    /**
     * Una misma identidad con cantidades diferentes no se suma ni se ignora silenciosamente.
     *
     * @return void
     */
    public function testContradictoryDuplicateIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AvailabilityCalculator)->calculate('0001', 'global', null, [
            new ReservationData('same', '0001', 'global', '1', ReservationData::FIXED),
            new ReservationData('same', '0001', 'global', '2', ReservationData::FIXED),
        ]);
    }

    /**
     * Mantiene NULL si se desconoce cualquiera de los compromisos.
     *
     * @return void
     */
    public function testUnknownCommitmentPropagatesWithoutZeroSubstitution(): void
    {
        $result = (new AvailabilityCalculator)->calculate('0001', 'global',
            new InventoryData('0001', 'global', '10', new DateTimeImmutable), [
                new ReservationData('fixed', '0001', 'global', null, ReservationData::FIXED),
                new ReservationData('manual', '0001', 'global', '2', ReservationData::MANUAL),
            ]);
        self::assertSame('10.000000', $result->onHand);
        self::assertNull($result->fixedReserved);
        self::assertSame('2.000000', $result->manualReserved);
        self::assertNull($result->totalCommitted);
        self::assertNull($result->available);
        self::assertNull($result->overcommitted);
        self::assertNull($result->positiveAvailable);
    }

    /**
     * Mantiene cantidades de adaptadores anteriores sin asignarles una clase inventada.
     *
     * @return void
     */
    public function testLegacyFourArgumentReservationIsStillCounted(): void
    {
        $result = (new AvailabilityCalculator)->calculate('0001', 'global', null,
            [new ReservationData('legacy', '0001', 'global', '2')]);
        self::assertSame('2.000000', $result->unclassifiedReserved);
        self::assertSame('2.000000', $result->totalCommitted);
        self::assertSame('0.000000', $result->manualReserved);
    }

    /**
     * Rechaza compromisos de otro producto o ubicación y cantidades negativas.
     *
     * @return void
     */
    public function testInvalidScopeAndNegativeReservationsAreRejected(): void
    {
        foreach ([
            new ReservationData('1', 'other', 'global', '1'),
            new ReservationData('1', '0001', 'other', '1'),
            new ReservationData('1', '0001', 'global', '-1'),
        ] as $reservation) {
            try {
                (new AvailabilityCalculator)->calculate('0001', 'global', null, [$reservation]);
                self::fail('Se aceptó una reserva inválida.');
            } catch (InvalidArgumentException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }
}
