<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Tests;
use Asisnet\Replenishment\Services\ExtendedCoverageEvaluator;
use PHPUnit\Framework\TestCase;

/** Verifica procedencia y límites de la hipótesis de entradas sin fuentes externas. */
final class ExtendedCoverageIncomingAssumptionTest extends TestCase
{
    /** Las entradas certificadas prevalecen sobre la autorización de cero, incluida ausencia certificada. @return void */
    public function testCertifiedInputsTakePrecedence(): void
    {
        $e=new ExtendedCoverageEvaluator();foreach(['0','30'] as $incoming){$r=$e->evaluateAccumulated('200','120',120,'10',$incoming,null,false,true);self::assertSame($incoming,$r['incoming_considered']);self::assertSame($incoming,$r['certified_incoming']);self::assertSame('certified_incoming',$r['incoming_source']);self::assertNull($r['incoming_assumption']);}self::assertSame('100',$r['central_balance']);
    }
    /** Sólo la autorización expresa permite cero; jamás altera la cantidad certificada desconocida. @return void */
    public function testExplicitZeroIsExploratoryAndDoesNotCertify(): void
    {
        $e=new ExtendedCoverageEvaluator();$unknown=$e->evaluateAccumulated('200','120',120,'10',null);self::assertNull($unknown['incoming_considered']);self::assertSame('unknown',$unknown['incoming_source']);self::assertFalse($unknown['candidate']);
        $r=$e->evaluateAccumulated('200','120',120,'10',null,'150',true,true);self::assertSame('0',$r['incoming_considered']);self::assertNull($r['certified_incoming']);self::assertSame('explicit_zero_incoming_assumption',$r['incoming_source']);self::assertSame($r['incoming_source'],$r['incoming_assumption']);self::assertSame('exploratory',$r['diagnostic_quality']);self::assertTrue($r['candidate']);self::assertSame('70',$r['central_balance']);self::assertSame('40',$r['conservative_balance']);self::assertFalse($r['excluded']);self::assertNull($r['confidence']);self::assertStringContainsString('exploratoria',$r['warning']);
    }
    /** La autorización no completa seguridad, demanda ni horizonte y conserva revisión ante discrepancias. @return void */
    public function testOtherUnknownsAndConflictingScenariosRemainProtected(): void
    {
        $e=new ExtendedCoverageEvaluator();foreach([['200','120',120,null,null],['200',null,120,'0',null],['200','120',null,'0',null]] as $args){$r=$e->evaluateAccumulated(...array_merge($args,[null,false,true]));self::assertSame('insufficient',$r['diagnostic_quality']);self::assertFalse($r['candidate']);}
        $r=$e->evaluateAccumulated('200','120',120,'10',null,'240',true,true);self::assertSame('scenario_conflict',$r['classification']);self::assertSame('Revisión necesaria',$r['warning']);self::assertFalse($r['candidate']);self::assertTrue($r['central_sufficient']);self::assertFalse($r['conservative_sufficient']);
    }
}
