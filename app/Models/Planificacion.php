<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Planificacion extends Model
{
    protected $table = 'planificacion'; // Nombre de tu tabla
    protected $primaryKey = 'id'; // La PK de la tabla planificacion
    public $timestamps = false; // Asumiendo que no tienes created_at/updated_at

    // Define los campos que son fillable si vas a usar create() o update() masivamente
    protected $fillable = [
        'id_aerolinea',
        'iata_aerolinea',
        'id_tripulante',
        'crew_id',
        'fecha_vuelo',
        'numero_vuelo',
        'hora_vuelo',
        'estatus',
    ];

    // Casting de tipos para facilitar el manejo
    protected $casts = [
        'fecha_vuelo' => 'date',
        // 'hora_vuelo' es time(16). Laravel puede tener problemas casteando solo 'time'.
        // Lo manejaremos como string y lo parsearemos con Carbon donde sea necesario.
    ];
}