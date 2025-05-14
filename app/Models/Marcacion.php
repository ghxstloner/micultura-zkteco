<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Marcacion extends Model
{
    /**
     * La tabla asociada con el modelo.
     */
    protected $table = 'marcacion';

    /**
     * La clave primaria para el modelo en Eloquent.
     */
    protected $primaryKey = 'id_marcacion';

    /**
     * Indica si la IDs son auto-incrementales.
     */
    public $incrementing = true;

    /**
     * El tipo de dato de la clave auto-incremental.
     */
    protected $keyType = 'int';

    public $timestamps = false;

    /**
     * Los atributos que se pueden asignar masivamente.
     */
    protected $fillable = [
        'id_planificacion', // Clave Primaria de la BD, necesita un valor.
        'id_tripulante',
        'fecha_marcacion',
        'hora_marcacion',
        'lugar_marcacion',
    ];
}