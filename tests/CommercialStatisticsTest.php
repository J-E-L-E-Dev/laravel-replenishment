<?php

declare(strict_types=1);

namespace Asisnet\Replenishment\Tests;

use Asisnet\Replenishment\Contracts\{CommercialHistorySource,ProductSource,SaleSource};
use Asisnet\Replenishment\DTO\{CommercialHistoryWindow,InventoryMovementData,ProductData};
use Asisnet\Replenishment\Models\{CommercialSalesDay,CommercialSalesRun};
use Asisnet\Replenishment\ReplenishmentServiceProvider;
use Asisnet\Replenishment\Services\{CommercialProfile,CommercialSalesQuery,CommercialSalesReport,CommercialSalesStore,ForecastQuery,ObservedDemandQuery,PackageMigrator};
use DateTimeImmutable;
use DateTimeZone;
use Orchestra\Testbench\TestCase;
use RuntimeException;

/** Prueba el ciclo de lectura, publicación y consulta con dos sesiones SQLite aisladas del ERP. */
final class CommercialStatisticsTest extends TestCase
{
    private string $path;
    private ProductData $master;
    private array $events = [];
    private bool $interrupt = false;
    private ?\Closure $duringRead = null;

    /** Registra el proveedor del paquete. @param mixed $app Aplicación de prueba. @return array Proveedores. */
    protected function getPackageProviders($app): array { return [ReplenishmentServiceProvider::class]; }

    /** Configura dos sesiones sobre el mismo archivo temporal. @param mixed $app Aplicación de prueba. @return void */
    protected function defineEnvironment($app): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'rep-stat-');
        foreach (['own', 'reader'] as $name) {
            $app['config']->set('database.connections.'.$name, ['driver'=>'sqlite','database'=>$this->path,'prefix'=>'']);
        }
        $app['config']->set('replenishment.connection', 'own');
        $app['config']->set('app.timezone','UTC');
        $app['config']->set('replenishment.performance_log', $this->name() === 'testPublicationTrace');
    }

    /** Prepara adaptadores verificables sin acceso comercial. @return void */
    protected function setUp(): void
    {
        parent::setUp();
        $this->master = new ProductData('P', 'Producto', 'CJA');
        $products = $this->createMock(ProductSource::class);
        $products->method('find')->willReturnCallback(fn () => $this->master);
        $source = $this->createMock(CommercialHistorySource::class);
        $source->method('window')->willReturnCallback(function ($product, $location, $from, $until) {
            $cut = $this->master->statisticsCutoffDate;
            $start = $cut === null ? $from : max($from, $this->date($cut)->modify('+1 day'));
            return new CommercialHistoryWindow($from, $start, $until, $cut, $this->movements($start, $until));
        });
        $legacy = $this->createMock(SaleSource::class);
        $legacy->expects(self::never())->method('between');
        foreach (['commercial_history'=>$source, 'products'=>$products, 'sales'=>$legacy] as $key=>$adapter) {
            $this->app['config']->set('replenishment.adapters.'.$key, $adapter::class);
            $this->app->instance($adapter::class, $adapter);
        }
    }

    /** Cierra sesiones y borra sólo el archivo temporal creado por esta prueba. @return void */
    protected function tearDown(): void
    {
        $this->app['db']->purge('reader');
        $this->app['db']->purge('own');
        parent::tearDown();
        unlink($this->path);
    }

    /** Construye un límite UTC. @param string $day Fecha civil. @return DateTimeImmutable Medianoche. */
    private function date(string $day): DateTimeImmutable { return new DateTimeImmutable($day, new DateTimeZone('UTC')); }

    /** Emite eventos sintéticos con posibilidad de interrupción tras la primera entrega.
     * @param DateTimeImmutable $from Inicio efectivo. @param DateTimeImmutable $until Fin exclusivo. @return iterable Flujo comercial. */
    private function movements(DateTimeImmutable $from, DateTimeImmutable $until): iterable
    {
        foreach ($this->events as [$id,$day,$type,$unit,$quantity]) {
            $date = $this->date($day);
            if ($date < $from || $date >= $until) { continue; }
            yield new InventoryMovementData($id,'P','L','01','007',$type,$date,$quantity,null,'999',null,null,null,null,
                historicalSaleQuantity: $quantity, historicalSaleUnit: $unit);
            if ($this->duringRead !== null) { ($this->duringRead)(); }
            if ($this->interrupt) { throw new RuntimeException('Fallo simulado'); }
        }
    }

    /** Devuelve alcance común. @return array Argumentos para lectura y materialización. */
    private function scope(): array { return ['P','L',$this->date('2026-01-01'),$this->date('2026-05-01'),'UTC']; }

    /** Compara la publicación con su restauración y mide el mismo volumen antes y después de agrupar escrituras. @return void */
    public function testPublicationVolumes(): void
    {
        app(PackageMigrator::class)->run();
        $db = app('db')->connection('own');
        $queries = $inserts = $publication = 0;
        $db->listen(function ($event) use (&$queries, &$inserts) {
            if ($event->connectionName === 'own') {
                $queries++;
                $inserts += (int) str_starts_with(strtolower($event->sql), 'insert ');
            }
        });
        CommercialSalesRun::creating(function () use (&$publication) { $publication = hrtime(true); });
        $store = app(CommercialSalesStore::class);
        $from = $this->date('2022-01-01');
        foreach ([30,90,365,730] as $length) {
            $this->events = [];
            for ($i=0; $i<$length; $i++) {
                $day = $from->modify("+{$i} days")->format('Y-m-d');
                if ($i%3 !== 0) { $this->events[] = ["F{$i}",$day,'FAC','UND','99999999999999999.123456']; }
                if ($i%3 === 2) {
                    $this->events[] = ["C{$i}",$day,'NCC','CJA','-2.123456'];
                    $this->events[] = ["D{$i}",$day,'NDC','CJA','0.000001'];
                }
            }
            $queries = $inserts = 0;
            if (function_exists('memory_reset_peak_usage')) { memory_reset_peak_usage(); }
            $memory = memory_get_usage();
            $start = hrtime(true);
            $series = $store->materialize('P','L',$from,$from->modify("+{$length} days"),'UTC');
            $end = hrtime(true);
            fwrite(STDOUT,sprintf("\n%d días | %d SQL | %d INSERT | %.2f ms publicación | %.2f ms total | %.2f MiB | %s\n",
                $length,$queries,$inserts,($end-$publication)/1e6,($end-$start)/1e6,(memory_get_peak_usage()-$memory)/1048576,
                substr(hash('sha256',serialize($series->days)),0,16)));
            $units=array_sum(array_map(static fn ($day)=>count($day['units']),$series->days));
            $expected=1+(int)ceil($length/100)+(int)ceil($units/100);
            self::assertSame($expected,$inserts);
            self::assertSame($expected+2,$queries);
            self::assertSame($series->days,$store->execution($series->runId)->days);
        }
    }

    /** Comprueba etapas, lotes, consultas agregadas y ausencia de enlaces SQL en la telemetría.
     * @return void */
    public function testPublicationTrace(): void
    {
        app(PackageMigrator::class)->run();
        $logs = [];
        $this->app['events']->listen(\Illuminate\Log\Events\MessageLogged::class, function ($event) use (&$logs) {
            if ($event->message === 'replenishment.performance') {
                $logs[$event->context['stage']] = $event->context;
            }
        });
        $this->events = [['1','2026-01-01','FAC','UNIDAD-PRIVADA','2.1']];
        $result = app(CommercialSalesStore::class)->materialize(...[...$this->scope(), static fn () => null]);
        foreach (['commercial_read','daily_aggregation','insert_header','insert_days','day_ids','insert_units','validate_units','on_published','commit'] as $stage) {
            self::assertArrayHasKey($stage,$logs);
            self::assertSame('P',$logs[$stage]['product']);
            self::assertGreaterThanOrEqual(0,$logs[$stage]['duration_ms']);
        }
        self::assertSame($result->runId,$logs['day_ids']['sales_run_id']);
        self::assertSame(120,$logs['insert_days']['records']);
        self::assertSame(2,$logs['insert_days']['batches']);
        self::assertSame(2,array_sum(array_column($logs['insert_days']['sql'],'count')));
        self::assertSame(1,$logs['daily_aggregation']['records']);
        self::assertStringNotContainsString('UNIDAD-PRIVADA',json_encode($logs,JSON_THROW_ON_ERROR));
    }

    /** Reconcilia unidades en días compartidos, semanas recortadas y años, incluido el neto negativo. @return void */
    public function testCalendarReconcilesWithoutAddingDifferentUnits(): void
    {
        $this->events = [
            ['1','2026-01-15','FAC','UND','120'], ['2','2026-01-17','NCC','UND','-10'],
            ['3','2026-02-01','FAC','UND','100'], ['4','2026-03-15','FAC','UND','60'],
            ['5','2026-03-15','FAC','CJA','5'], ['6','2026-04-02','FAC','CJA','15'],
            ['7','2026-04-12','NCC','CJA','-2'],
        ];
        $data = app(CommercialSalesQuery::class)->read(...$this->scope());
        $report = app(CommercialSalesReport::class)->breakdown($data);
        self::assertSame(['270.000000','18.000000'],array_column($report['summary']['totals_by_unit'],'net_sales_units'));
        self::assertSame(['280.000000','20.000000'],array_column($report['years']['2026']['totals_by_unit'],'gross_sales_units'));
        self::assertSame(['10.000000','2.000000'],array_column($report['summary']['totals_by_unit'],'returned_units'));
        self::assertSame(['60.000000','5.000000'],array_column($report['months']['2026-03']['totals_by_unit'],'gross_sales_units'));
        self::assertSame('-10.000000',$data->days['2026-01-17']['units']['UND']['net_sales_units']);
        self::assertSame([], $data->days['2026-01-01']['units']);
        self::assertSame('zero_observed', $data->days['2026-01-01']['state']);
        self::assertSame('2026-01-31',$report['weeks']['2026-01-26']['last_day']);
        self::assertSame('2026-02-01',$report['weeks']['2026-02-01']['last_day']);
        self::assertTrue($report['years']['2026']['partial_calendar_period']);
        foreach (['weeks','months','years'] as $level) {
            $totals = [];
            foreach ($report[$level] as $period) {
                foreach ($period['totals_by_unit'] as $unit) {
                    $totals[$unit['unit']] = \Brick\Math\BigDecimal::of($totals[$unit['unit']] ?? 0)->plus($unit['net_sales_units']);
                }
            }
            self::assertSame('270.000000',(string)$totals['UND']);
            self::assertSame('18.000000',(string)$totals['CJA']);
        }
        app(PackageMigrator::class)->run();
        $store=app(CommercialSalesStore::class);
        $published=$store->materialize(...$this->scope());
        $dashboard=app(\Asisnet\Replenishment\Services\DashboardSalesQuery::class);
        $display=$dashboard->read('P','L','2026-01','2026-05','UTC',$this->date('2026-05-02'),master:$this->master);
        self::assertSame(['UND','CJA'],$display['units']);
        self::assertSame($published->runId,$display['sales_run_id']);
        self::assertSame(['270.000000','18.000000'],array_column($display['summary']['totals_by_unit'],'net_sales_units'));
        self::assertSame(['60.000000','5.000000'],array_column($display['rows'][2]['totals_by_unit'],'net_sales_units'));
        self::assertNull($display['summary']['net_sales_units']);
        $this->events[4][4]='99';
        $store->materialize(...$this->scope());
        $this->master=new ProductData('P','Producto','BOLS');
        $weeks=$dashboard->weeks('P','L',2026,3,'UTC',$this->date('2026-05-02'),$published->runId,'2026-01-01','2026-05-01');
        self::assertSame($published->runId,$weeks['sales_run_id']);
        self::assertSame('CJA',$weeks['statistics']['profile']['current_unit']);
        self::assertSame(['60.000000','5.000000'],array_column($weeks['summary']['totals_by_unit'],'net_sales_units'));
        $outside=$dashboard->observations('P','L',$this->date('2025-12-31'),$this->date('2026-01-18'),$published->runId);
        self::assertSame(1,$outside['summary']['unknown_days']);
        self::assertNull($outside['summary']['totals_by_unit']);
        self::assertSame('110.000000',$outside['summary']['observed_by_unit'][0]['net_sales_units']);
        $this->events[4][4]='5';
        $this->master=new ProductData('P','Producto','CJA');
        $projection = app(ObservedDemandQuery::class)->analyze(...array_slice($this->scope(),0,4));
        self::assertSame(CommercialProfile::VERSION,$projection->audit()['algorithm_version']);
        self::assertNull($projection->days['2026-03-15']['gross_sales_units']);
        self::assertSame('15.000000',$projection->days['2026-04-02']['gross_sales_units']);
        self::assertNull($projection->summary['net_sales_units']);
        $forecast = app(ForecastQuery::class)->analyze(...[...array_slice($this->scope(),0,4),1,false,false]);
        self::assertNull($forecast->audit()['forecasts'][0]['forecast_quantity']);
    }

    /** Verifica un pronóstico calculable tras tres meses homogéneos sin contribución de la unidad anterior. @return void */
    public function testForecastUsesOnlyComparableSuffix(): void
    {
        $this->events = [['older','2026-01-01','FAC','UND','120']];
        for ($date = $this->date('2026-02-01'); $date < $this->date('2026-05-01'); $date = $date->modify('+1 day')) {
            $day = $date->format('Y-m-d');
            $this->events[] = [$day, $day, 'FAC', 'CJA', '1'];
        }
        $forecast = fn () => app(ForecastQuery::class)->analyze(...[...array_slice($this->scope(),0,4),1,false,false])->audit();
        $before = $forecast();
        self::assertSame('CJA',$before['base_unit']);
        self::assertSame(3,$before['training']['consecutive_periods']);
        self::assertNotNull($before['forecasts'][0]['forecast_quantity']);
        $this->events[0][4] = '9000000';
        self::assertSame($before['forecasts'][0]['forecast_quantity'],$forecast()['forecasts'][0]['forecast_quantity']);
    }

    /** Comprueba el corte y el mínimo contra la ejecución disponible, aunque la solicitud comience antes.
     * @return void */
    public function testPolicyUsesOnlyMaterializedEffectiveDays(): void
    {
        app(PackageMigrator::class)->run();
        $this->master = new ProductData('P','Producto','UND','2026-08-01');
        $this->events = [['1','2026-11-01','FAC','UND','3']];
        $until = $this->date('2026-11-18');
        app(CommercialSalesStore::class)->materialize('P','L',$this->date('2026-08-02'),$until,'UTC');
        $settings = \Asisnet\Replenishment\Services\PolicyDemandSettings::normalize(['continuity'=>'assumed']);
        [$series,$window] = app(\Asisnet\Replenishment\Services\PlanningHistory::class)->read(
            $this->master,'L',$until->modify('-365 days'),$until,$settings,'comprador');
        self::assertSame(108,$window['effective_days']);
        self::assertSame(257,$window['excluded_by_cutoff']);
        self::assertSame(107,$window['assumed_days']);
        $policy = ['active'=>true,'analysis_days'=>365,'minimum_history_days'=>180,'forecast_horizon_days'=>180,'demand_settings'=>$settings];
        $resolver = app(\Asisnet\Replenishment\Services\PolicyDemandResolver::class);
        self::assertNull($resolver->resolve($series,$policy,'UND',$until,$until->modify('+90 days'),null,true)['quantity']);
        $policy['minimum_history_days']=108;
        self::assertSame('1/36',$resolver->resolve($series,$policy,'UND',$until,$until->modify('+90 days'),null,true)['rate_exact']);
    }

    /** Conserva las ventas incompatibles en la fotografía y delimita sólo la estrategia autorizada.
     * @return void */
    public function testPolicySegmentNeverPadsIncompatibleOrMissingBlocks(): void
    {
        app(PackageMigrator::class)->run();
        $this->events = [['1','2026-02-01','FAC','UND','200'],['2','2026-02-01','FAC','CJA','3'],
            ['3','2026-04-15','FAC','CJA','3']];
        app(CommercialSalesStore::class)->materialize(...$this->scope());
        $from = $this->date('2026-01-01');
        $until = $this->date('2026-05-01');
        $analyze = function (array $parameters, int $minimum=50) use ($from,$until): array {
            $settings = \Asisnet\Replenishment\Services\PolicyDemandSettings::normalize($parameters + ['continuity'=>'assumed']);
            [$series,$window] = app(\Asisnet\Replenishment\Services\PlanningHistory::class)->read($this->master,'L',$from,$until,$settings,'comprador');
            $policy = ['active'=>true,'analysis_days'=>120,'minimum_history_days'=>$minimum,'forecast_horizon_days'=>180,'demand_settings'=>$settings];
            return [$window,app(\Asisnet\Replenishment\Services\PolicyDemandResolver::class)->resolve($series,$policy,'CJA',$until,$until->modify('+90 days'),null,true)];
        };
        self::assertNull($analyze([])[1]['quantity']);
        [$window,$recent] = $analyze(['strategy'=>'recent_average','recent_days'=>30]);
        self::assertSame('2026-02-02',$window['segment_start']);
        self::assertSame(1,$window['incompatible_days']);
        self::assertSame('1/10',$recent['rate_exact']);
        self::assertNull($analyze(['strategy'=>'recent_average','recent_days'=>30],100)[1]['quantity']);
        self::assertNull($analyze(['strategy'=>'weighted_average','blocks'=>[['days'=>30,'weight'=>'50'],['days'=>60,'weight'=>'50']]])[1]['quantity']);
    }

    /** Distingue devoluciones, reversiones, incidencias locales y fallos que invalidan toda la lectura. @return void */
    public function testPartialQuantitiesAndInterruptedOrDuplicateStream(): void
    {
        $this->events = [['1','2026-03-15','NDC','CJA','2'],['2','2026-03-15','FAC',null,'3']];
        $query = app(CommercialSalesQuery::class);
        $data = $query->read(...$this->scope());
        $report = app(CommercialSalesReport::class)->breakdown($data);
        self::assertSame('incomplete',$data->status);
        self::assertNull($report['summary']['totals_by_unit']);
        self::assertSame('2.000000',$report['summary']['observed_by_unit'][0]['reversed_return_units']);
        self::assertSame(2,$data->days['2026-03-15']['event_count']);
        $this->events[] = $this->events[0];
        $duplicate = $query->read(...$this->scope());
        self::assertSame('duplicate_identity',$duplicate->limitation);
        self::assertSame('unknown',$duplicate->days['2026-03-15']['state']);
        $this->interrupt = true;
        self::assertSame('commercial_source_failed',$query->read(...$this->scope())->limitation);
        config(['replenishment.adapters.commercial_history'=>'missing']);
        self::assertSame('unknown',app(ObservedDemandQuery::class)->analyze(...array_slice($this->scope(),0,4))->statistics['status']);
    }

    /** Verifica el corte inclusivo, todos los días fuera de ventana y el perfil del maestro. @return void */
    public function testCutoffAndEmptyEffectiveWindow(): void
    {
        $this->master = new ProductData('P','Producto','UND','2026-08-01');
        $this->events = [['1','2026-08-01','FAC','UND','9'],['2','2026-08-02','FAC','UND','1'],['3','2026-10-02','FAC','UND','1']];
        $data = app(CommercialSalesQuery::class)->read('P','L',$this->date('2026-01-01'),$this->date('2026-11-18'),'UTC');
        $summary = app(CommercialSalesReport::class)->breakdown($data)['summary'];
        self::assertSame('2.000000',$summary['totals_by_unit'][0]['net_sales_units']);
        self::assertSame(108,$summary['effective_days']);
        self::assertSame(213,$summary['outside_cutoff_days']);
        self::assertNull($data->days['2026-08-01']['event_count']);
        $empty = app(CommercialSalesQuery::class)->read(...$this->scope());
        self::assertSame(['outside_cutoff'],array_values(array_unique(array_column($empty->days,'state'))));
        self::assertNull(app(CommercialSalesReport::class)->breakdown($empty)['summary']['totals_by_unit']);
    }

    /** Impide unir subintervalos y verifica la huella ante reordenación y cambios semánticos. @return void */
    public function testDisjointRunsAndCanonicalProfile(): void
    {
        app(PackageMigrator::class)->run();
        $store = app(CommercialSalesStore::class);
        $rules = [['operator'=>'like','value'=>'31%'], ['operator'=>'in','value'=>['02','01']]];
        config(['replenishment.sales.document_exclusions'=>$rules]);
        $first = $store->materialize('P','L',$this->date('2026-01-01'),$this->date('2026-02-01'),'UTC');
        config(['replenishment.sales.document_exclusions'=>[['value'=>['01','02'],'operator'=>'in'],$rules[0]]]);
        $second = $store->materialize('P','L',$this->date('2026-02-01'),$this->date('2026-05-01'),'UTC');
        self::assertSame(CommercialProfile::hash($first->profile),CommercialProfile::hash($second->profile));
        self::assertNotSame(CommercialProfile::hash($first->profile),
            CommercialProfile::hash(array_replace($first->profile,['aggregator'=>'otra-version'])));
        self::assertSame('refresh_required',$store->cached(...[...$this->scope(),$this->master])->status);
        self::assertSame($first->runId,$store->cached('P','L',$this->date('2026-01-10'),$this->date('2026-01-15'),'UTC',$this->master)->runId);
        $dashboard = app(\Asisnet\Replenishment\Services\DashboardSalesQuery::class)->read(
            'P','L','2026-01','2026-05','UTC',$this->date('2026-05-01'));
        self::assertSame('refresh_required',$dashboard['statistics']['status']);
        self::assertStringContainsString('Actualización necesaria',$dashboard['source']);
        self::assertSame([null],array_values(array_unique(array_column($dashboard['rows'],'net_sales_units'))));
    }

    /** Comprueba errores SQL y alteraciones intermedias en lotes parciales sin tocar una publicación previa.
     * @return void */
    public function testRollbackOfBatchesAndVerification(): void
    {
        app(PackageMigrator::class)->run();
        config(['replenishment.statistics_batch_size'=>2]);
        $db=app('db')->connection('own');
        $store=app(CommercialSalesStore::class);
        $this->events=[['1','2026-01-01','FAC','UND','4.123456'],['2','2026-01-02','FAC','CJ','2'],
            ['3','2026-01-03','NCC','UND','-3']];
        $scope=['P','L',$this->date('2026-01-01'),$this->date('2026-01-06'),'UTC'];
        $original=$store->materialize(...$scope);
        foreach ([
            "BEFORE INSERT ON replenishment_sales_days WHEN NEW.sales_date='2026-01-03' BEGIN SELECT RAISE(ABORT,'día'); END",
            "BEFORE INSERT ON replenishment_sales_unit_totals WHEN NEW.net_sales_units='-3.000000' BEGIN SELECT RAISE(ABORT,'unidad'); END",
            "AFTER INSERT ON replenishment_sales_days WHEN NEW.sales_date='2026-01-03' BEGIN DELETE FROM replenishment_sales_days WHERE id=NEW.id; END",
            "AFTER INSERT ON replenishment_sales_unit_totals BEGIN UPDATE replenishment_sales_unit_totals SET unit_hash='alterado' WHERE id=NEW.id; END",
        ] as $trigger) {
            $db->statement('CREATE TRIGGER fallo '.$trigger);
            try {
                $store->materialize(...$scope);
                self::fail('Se confirmó una publicación dañada.');
            } catch (RuntimeException $error) {
                self::assertNotSame('', $error->getMessage());
            } finally {
                $db->statement('DROP TRIGGER fallo');
            }
            self::assertSame(1,CommercialSalesRun::count());
            self::assertSame(5,CommercialSalesDay::count());
            self::assertSame(3,\Asisnet\Replenishment\Models\CommercialSalesUnit::count());
            self::assertEquals($original->days,$store->execution($original->runId)->days);
        }
    }

    /** Usa claves autoincrementales separadas, prueba tamaños extremos y compara todos los lectores.
     * @return void */
    public function testSparseIdsExactComponentsAndImmutableReaders(): void
    {
        app(PackageMigrator::class)->run();
        $db=app('db')->connection('own');
        $db->statement('CREATE TRIGGER saltos AFTER INSERT ON replenishment_sales_days BEGIN UPDATE replenishment_sales_days SET id=id+7 WHERE id=NEW.id; END');
        $this->master=new ProductData('P','Producto','UND','2026-01-01');
        $this->events=[['1','2026-01-02','FAC','UND','999999999999999999.999999'],
            ['2','2026-01-02','NCC','CJ','-0.000002'],['3','2026-01-02','NDC','CJ','0.000001']];
        $scope=['P','L',$this->date('2026-01-01'),$this->date('2026-01-06'),'UTC'];
        $store=app(CommercialSalesStore::class);
        foreach ([1,3,10000] as $size) {
            config(['replenishment.statistics_batch_size'=>$size]);
            $data=$store->materialize(...$scope);
            self::assertSame('outside_cutoff',$data->days['2026-01-01']['state']);
            self::assertNull($data->days['2026-01-01']['event_count']);
            self::assertSame('-0.000001',$data->days['2026-01-02']['units']['CJ']['net_sales_units']);
            self::assertSame('zero_observed',$data->days['2026-01-03']['state']);
            $ids=CommercialSalesDay::where('run_id',$data->runId)->orderBy('id')->pluck('id');
            self::assertGreaterThan(1,$ids[1]-$ids[0]);
            self::assertEquals($data->days,$store->cached(...[...$scope,$this->master])->days);
            self::assertEquals($data->days,$store->historicalWindow($data->runId,'P','L',$scope[2],$scope[3])->days);
        }
        $row=CommercialSalesDay::firstOrFail();
        $this->expectException(\LogicException::class);
        $row->update(['issues'=>['intento de modificación']]);
    }

    /** Rechaza los mismos atributos inválidos al preparar los lotes y al guardar por Eloquent.
     * @return void */
    public function testBatchUsesDomainValidation(): void
    {
        $valid=['unit'=>'CJ','day_id'=>1,'unit_hash'=>hash('sha256','CJ'),'event_count'=>1,
            'gross_sales_units'=>'1','returned_units'=>'0','reversed_return_units'=>'0','net_sales_units'=>'1'];
        foreach ([['unit'=>str_repeat('X',101)],['unit'=>''],['event_count'=>'1.5'],['gross_sales_units'=>'1.0000001'],
            ['returned_units'=>'-1'],['net_sales_units'=>'1e2'],['unit_hash'=>null]] as $invalid) {
            try {
                \Asisnet\Replenishment\Models\CommercialSalesUnit::validatedInsert($invalid+$valid);
                self::fail('Se aceptó un agregado inválido.');
            } catch (\InvalidArgumentException $error) { self::assertNotSame('',$error->getMessage()); }
        }
        $day=CommercialSalesDay::validatedInsert(['run_id'=>1,'sales_date'=>'2026-01-01','state'=>'outside_cutoff',
            'event_count'=>null,'issues'=>['unidad histórica']]);
        self::assertNull($day['event_count']);
        self::assertSame(['unidad histórica'],json_decode($day['issues'],true,512,JSON_THROW_ON_ERROR));
        $this->expectException(\InvalidArgumentException::class);
        CommercialSalesDay::validatedInsert(['run_id'=>1,'sales_date'=>'2026-01-01','state'=>'unknown']);
    }

    /** Comprueba aislamiento entre sesiones, reversión de escrituras parciales y selección de una ejecución íntegra. @return void */
    public function testAtomicPublicationAndProfileIsolation(): void
    {
        app(PackageMigrator::class)->run();
        $this->events = [['1','2026-01-01','FAC','CJA','1']];
        $store = app(CommercialSalesStore::class);
        $scope = $this->scope();
        $first = $store->materialize(...$scope);
        $reader = $this->app['db']->connection('reader');
        $seen = false;
        $reject = function () use ($reader,&$seen): void {
            $seen = true;
            self::assertSame(1,$reader->table('replenishment_sales_runs')->count());
            throw new RuntimeException('Fallo de publicación');
        };
        try {
            $store->materialize(...[...$scope,$reject]);
            self::fail('Debió revertir la segunda cabecera y su primer día.');
        } catch (RuntimeException $error) {
            self::assertSame('Fallo de publicación',$error->getMessage());
        }
        self::assertTrue($seen);
        self::assertSame(1,CommercialSalesRun::count());
        $this->interrupt = true;
        try {
            $store->materialize(...$scope);
            self::fail('No se debe materializar un flujo interrumpido.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('incompleta',$error->getMessage());
        }
        self::assertSame($first->runId,$store->cached(...[...$scope,$this->master])->runId);
        self::assertSame('refresh_required',$store->cached(...$scope)->status);
        self::assertSame('master_not_rechecked',$store->cached(...[...$scope,null,true])->limitation);
        self::assertSame('refresh_required',$store->cached(...[...$scope,new ProductData('P','Producto','UND')])->status);
        self::assertSame('refresh_required',$store->cached(...[...$scope,new ProductData('P','Producto','CJA','2025-01-01')])->status);
        $this->interrupt = false;
        // Intercala una publicación completa mientras la otra todavía está leyendo, sin transacción propia abierta.
        $this->duringRead = function () use ($store,$scope): void {
            $this->duringRead = null;
            self::assertSame(0,$this->app['db']->connection('own')->transactionLevel());
            $this->events = [['2','2026-01-01','FAC','CJA','7']];
            $store->materialize(...$scope);
        };
        $slower = $store->materialize(...$scope);
        $latest = $store->cached(...[...$scope,$this->master]);
        self::assertNotSame($slower->runId,$latest->runId);
        self::assertSame('7.000000',$latest->days['2026-01-01']['units']['CJA']['net_sales_units']);
        self::assertSame('1.000000',$store->execution($slower->runId)->days['2026-01-01']['units']['CJA']['net_sales_units']);
        foreach (['normalizer_version'=>'changed','document_exclusions'=>[['operator'=>'=','value'=>'03']]] as $key=>$value) {
            config(['replenishment.sales.'.$key=>$value]);
            self::assertSame('refresh_required',$store->cached(...[...$scope,$this->master])->status);
            config(['replenishment.sales.'.$key=>$key==='document_exclusions'?[]:'commercial-history-1']);
        }
        self::assertSame('refresh_required',$store->cached('P','L',$scope[2],$scope[3],'Etc/UTC',$this->master)->status);
        self::assertSame($first->days,$store->execution($first->runId)->days);
    }
}
