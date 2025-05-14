<?php

namespace App\Models; // O el namespace donde tengas tus modelos

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;

class Tripulante extends Model
{
    protected $table = 'tripulantes';
    protected $primaryKey = 'id_tripulante';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = false;

    protected $fillable = [
        'id_aerolinea',
        'crew_id',
        'nombres',
        'apellidos',
        'pasaporte',
        'identidad',
        'posicion',
        'imagen', // Nombre del archivo de la imagen
    ];

    /**
     * Accesor para obtener el nombre completo del tripulante.
     */
    protected function nombresApellidos(): Attribute
    {
        return Attribute::make(
            get: fn ($value, $attributes) => trim(($attributes['nombres'] ?? '') . ' ' . ($attributes['apellidos'] ?? '')),
        );
    }

}