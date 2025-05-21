<?php

namespace App\Models\ZKTeco\ProFaceX;

use Illuminate\Database\Eloquent\Model;

/**
 * Clase base para todos los modelos de ProFaceX
 * Configura la conexión a la base de datos específica para todas las tablas de ProFaceX
 */
abstract class ProFxModel extends Model
{
    /**
     * Conexión a la base de datos
     *
     * @var string
     */
    protected $connection = 'profacex_db';
}
