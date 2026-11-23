<?php

declare(strict_types=1);
namespace Asisnet\Replenishment\Services;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

/** Valida exclusiones comerciales portables sin imponer documentos de un ERP. */
final class DocumentExclusions
{
    /** @var array Lista validada que conserva códigos textuales y ceros iniciales. */
    public readonly array $rules;
    /** Recibe reglas declaradas por el consumidor. @param array $rules Lista de operadores y valores. @throws InvalidArgumentException Ante operadores, listas o valores inválidos. */
    public function __construct(array $rules=[])
    {
        if(!array_is_list($rules)){throw new InvalidArgumentException('Las exclusiones documentales deben ser una lista.');}
        foreach($rules as $rule){if(!is_array($rule)||array_diff(array_keys($rule),['operator','value'])!==[]||!isset($rule['operator'],$rule['value'])||!in_array($rule['operator'],['like','=','in'],true)){throw new InvalidArgumentException('Exclusión documental inválida: use like, = o in.');}
            $values=$rule['operator']==='in'?$rule['value']:[$rule['value']];if(!is_array($values)||!array_is_list($values)||$values===[]){throw new InvalidArgumentException('IN requiere una lista textual no vacía.');}foreach($values as $value){if(!is_string($value)||trim($value)===''||str_contains($value,"\0")){throw new InvalidArgumentException('Los valores documentales deben ser textos no vacíos; no se admiten números ni listas anidadas.');}}
        }$this->rules=$rules;
    }
    /** Aplica NOT de la disyunción mediante parámetros; conserva NULL para que el adaptador diagnostique identidad ausente. @param Builder $query Consulta comercial. @param string $column Columna validada por el adaptador. @return Builder Consulta modificada. @throws InvalidArgumentException Ante identificador inseguro. */
    public function apply(Builder $query,string $column): Builder
    {
        if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?$/D',$column)){throw new InvalidArgumentException('Columna documental inválida.');}
        if($this->rules===[]){return $query;}
        return $query->where(function(Builder $outer)use($column):void{$outer->whereNull($column)->orWhereNot(function(Builder $excluded)use($column):void{foreach($this->rules as $rule){if($rule['operator']==='in'){$excluded->orWhereIn($column,$rule['value']);}else{$excluded->orWhere($column,$rule['operator'],$rule['value']);}}});});
    }
    /** Identifica las reglas exactas sin inferir equivalencia entre perfiles diferentes. @return string Huella reproducible para auditoría y caché. */
    public function fingerprint(): string {return hash('sha256',json_encode($this->rules,JSON_THROW_ON_ERROR));}
}
