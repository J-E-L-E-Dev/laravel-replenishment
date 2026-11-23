<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Tests;
use Asisnet\Replenishment\Services\ExtendedCoverageEvaluator;
use PHPUnit\Framework\TestCase;
use DateTimeImmutable;

/** Verifica balances acumulados y fechas sin bases ni fuentes externas. */
final class ExtendedCoverageEvaluatorTest extends TestCase
{
    /** Contrasta suficiencia central, conservadora y contradicciones sin exclusión automática. @return void */
    public function testScenarioClassification(): void
    {
        $e=new ExtendedCoverageEvaluator();$central=$e->evaluateAccumulated('200','120',120,'10','0');self::assertTrue($central['candidate']);self::assertTrue($central['central_sufficient']);self::assertNull($central['conservative_sufficient']);
        $conflict=$e->evaluateAccumulated('200','120',120,'10','0','240',true);self::assertTrue($conflict['central_sufficient']);self::assertFalse($conflict['conservative_sufficient']);self::assertFalse($conflict['candidate']);self::assertSame('scenario_conflict',$conflict['classification']);self::assertSame('Revisión necesaria',$conflict['warning']);self::assertFalse($conflict['excluded']);self::assertFalse($e->evaluateAccumulated('200','120',120,'10','0','240')['candidate']);
        $both=$e->evaluateAccumulated('200','120',120,'10','0','180',true);self::assertSame('both_sufficient',$both['classification']);self::assertTrue($both['candidate']);self::assertSame('10',$both['conservative_balance']);self::assertNull($both['confidence']);
        $neither=$e->evaluateAccumulated('100','120',120,'10','0','180',true);self::assertSame('insufficient_coverage',$neither['classification']);self::assertFalse($neither['candidate']);
    }
    /** Seguridad, pendientes, demanda o plazo desconocidos no acreditan cobertura. @return void */
    public function testUnknownsStayUnknownAndLegacySignatureIsConservative(): void
    {
        $e=new ExtendedCoverageEvaluator();foreach([['200','120',120,null,'0'],['200','120',120,'0',null],['200',null,120,'0','0'],['200','120',null,'0','0']] as $args){$r=$e->evaluateAccumulated(...$args);self::assertSame('insufficient_data',$r['classification']);self::assertFalse($r['candidate']);self::assertNull($r['central_sufficient']);}
        self::assertFalse($e->evaluateAccumulated('200','120',120,'0','0',null,true)['candidate']);self::assertFalse($e->evaluate('900','3',180)['candidate']);
    }
    /** La disponibilidad no está reducida por demanda previa; las entradas y seguridad se usan una sola vez. @return void */
    public function testNoDoubleCounting(): void
    {
        $r=(new ExtendedCoverageEvaluator())->evaluateAccumulated('100','120',120,'10','30','125',true);self::assertSame('0',$r['central_balance']);self::assertSame('-5',$r['conservative_balance']);self::assertFalse($r['candidate']);
    }
    /** Sólo cuenta entradas certificadas dentro del intervalo semiabierto y rechaza calendarios inciertos. @return void */
    public function testCertifiedIncomingDates(): void
    {
        $e=new ExtendedCoverageEvaluator();$from=new DateTimeImmutable('2026-01-01T00:00:00-04:00');$until=$from->modify('+120 days');$input=['status'=>'certified_positive','calendar_known'=>true,'total'=>'90','lines'=>[['id'=>'a','quantity'=>'10','expected_at'=>$from->format(DATE_ATOM)],['id'=>'b','quantity'=>'20','expected_at'=>$until->modify('-1 day')->format(DATE_ATOM)],['id'=>'c','quantity'=>'30','expected_at'=>$until->format(DATE_ATOM)],['id'=>'d','quantity'=>'30','expected_at'=>$until->modify('+1 day')->format(DATE_ATOM)]]];
        self::assertSame('30',$e->incomingWithin($input,$from,$until));self::assertNull($e->incomingWithin(array_replace($input,['calendar_known'=>false]),$from,$until));self::assertNull($e->incomingWithin(array_replace($input,['status'=>'assumed_absent']),$from,$until));self::assertNull($e->incomingWithin(array_replace($input,['total'=>'100']),$from,$until));
        self::assertSame('0',$e->incomingWithin(['status'=>'certified_absent','calendar_known'=>true,'total'=>'0','lines'=>[]],$from,$until));$input['lines'][0]['expected_at']=$from->modify('-1 day')->format(DATE_ATOM);self::assertNull($e->incomingWithin($input,$from,$until));
    }
}
