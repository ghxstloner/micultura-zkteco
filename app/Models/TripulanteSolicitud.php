<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Facades\Hash;

class TripulanteSolicitud extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'tripulantes_solicitudes';
    protected $primaryKey = 'id_solicitud';
    public $incrementing = true;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'crew_id',
        'nombres',
        'apellidos',
        'pasaporte',
        'identidad',
        'posicion',
        'imagen',
        'estado',
        'activo',
        'password',
        'fecha_solicitud',
        'fecha_aprobacion',
        'motivo_rechazo',
        'aprobado_por',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'fecha_solicitud' => 'datetime',
            'fecha_aprobacion' => 'datetime',
            'password' => 'hashed',
            'activo' => 'boolean',
        ];
    }

    /**
     * Constantes para estados
     */
    const ESTADO_PENDIENTE = 'Pendiente';
    const ESTADO_APROBADO = 'Aprobado';
    const ESTADO_DENEGADO = 'Denegado';

    /**
     * Relación con posición
     */
    public function posicionModel()
    {
        return $this->belongsTo(Posicion::class, 'posicion', 'id_posicion');
    }

    /**
     * Accessor para nombre completo
     */
    public function getNombresApellidosAttribute()
    {
        return $this->nombres . ' ' . $this->apellidos;
    }

    /**
     * Accessor para imagen URL
     */
    public function getImagenUrlAttribute()
    {
        if (!$this->imagen) {
            return null;
        }

        // Construir URL de imagen basada en crew_id
        return "https://crew.amaxoniaerp.com/images/crew/{$this->crew_id}/{$this->imagen}";
    }

    /**
     * Verificar si está aprobado
     */
    public function isApproved(): bool
    {
        return $this->estado === self::ESTADO_APROBADO;
    }

    /**
     * Verificar si está pendiente
     */
    public function isPending(): bool
    {
        return $this->estado === self::ESTADO_PENDIENTE;
    }

    /**
     * Verificar si está denegado
     */
    public function isDenied(): bool
    {
        return $this->estado === self::ESTADO_DENEGADO;
    }

    /**
     * Verificar si está activo
     */
    public function isActive(): bool
    {
        return $this->activo;
    }

    /**
     * Aprobar solicitud
     */
    public function approve($approvedBy = null)
    {
        $this->update([
            'estado' => self::ESTADO_APROBADO,
            'fecha_aprobacion' => now(),
            'aprobado_por' => $approvedBy,
            'motivo_rechazo' => null,
        ]);
    }

    /**
     * Denegar solicitud
     */
    public function deny($reason = null, $deniedBy = null)
    {
        $this->update([
            'estado' => self::ESTADO_DENEGADO,
            'motivo_rechazo' => $reason,
            'aprobado_por' => $deniedBy,
            'fecha_aprobacion' => null,
        ]);
    }

    /**
     * Scopes
     */
    public function scopePendientes($query)
    {
        return $query->where('estado', self::ESTADO_PENDIENTE);
    }

    public function scopeAprobados($query)
    {
        return $query->where('estado', self::ESTADO_APROBADO);
    }

    public function scopeDenegados($query)
    {
        return $query->where('estado', self::ESTADO_DENEGADO);
    }

    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    public function scopeBuscarPorNombre($query, $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('nombres', 'like', "%{$search}%")
              ->orWhere('apellidos', 'like', "%{$search}%")
              ->orWhere('crew_id', 'like', "%{$search}%")
              ->orWhere('pasaporte', 'like', "%{$search}%");
        });
    }
}