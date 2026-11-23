<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Models;
/** Estado durable de generación; las decisiones se auditan por separado. */
final class ProposalGenerationItem extends ReplenishmentModel
{
    /** @var string Tabla propia. */
    protected $table = 'replenishment_generation_items';
    /** @var array<string> Atributos de dominio autorizados. */
    protected $fillable = ['generation_id','relation_id','product_id','status','result','decision','saved'];
    /** @var array<string,string> JSON exacto y tipos estructurales. */
    protected $casts = ['result' => 'array','decision' => 'array','saved' => 'boolean', 'row_summary'=>'array','preparation'=>'array','revised_result'=>'array','previous_choice'=>'array',
        'attempts'=>'integer','requires_review'=>'boolean','lease_until'=>'immutable_datetime'];
    /** @var array Campos internos de trabajo omitidos en respuestas serializadas. */
    protected $hidden = ['lease_token','revised_result','row_summary'];
    /** @var array<string> Selección congelada. */
    protected array $fixedFields = ['generation_id','relation_id','product_id'];
    /** Obtiene la revisión vigente para los lectores conservando la primera evidencia física.
     * @param mixed $value JSON original. @return array|null Resultado vigente. */
    public function getResultAttribute($value): ?array
    {
        return $this->revised_result ?? ($value === null ? null : $this->fromJson($value));
    }

    /** Impide sustituir un cálculo original ya publicado. @return void @throws \LogicException Si se modifica evidencia histórica. */
    protected function validatePersistence(): void
    {
        parent::validatePersistence();if($this->exists&&$this->getRawOriginal('result')!==null&&$this->isDirty('result')){throw new \LogicException('El resultado original es inmutable; inicie otra ejecución.');}
    }
}
