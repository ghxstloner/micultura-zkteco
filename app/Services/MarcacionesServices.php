<?php

namespace App\Services;

use App\Models\Marcacion;
use App\Models\Planificacion;
use App\Models\ZKTeco\ProFaceX\ProFxAttLog;
use App\Models\ZKTeco\ProFaceX\ProFxDeviceInfo;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class MarcacionesServices
{
    /** @param ProFxAttLog[] $proFxAttLogList */
    public function registrarMarcaciones(array $proFxAttLogList)
    {
        foreach ($proFxAttLogList as $proFxAttLog) {
            try {
                $id_tripulante = intval($proFxAttLog->USER_PIN);
                $tiempoMarcacionCompleto = Carbon::parse($proFxAttLog->VERIFY_TIME);
                $fechaMarcacion = $tiempoMarcacionCompleto->toDateString();
                $horaMarcacion = $tiempoMarcacionCompleto->toTimeString();
                $id_planificacion = 0; // Inicializa siempre en 0
                $crew_id = null;

                // 1. Buscar el tripulante
                $tripulante = \App\Models\Tripulante::where('id_tripulante', $id_tripulante)->first();

                if ($tripulante && $tripulante->crew_id) {
                    $crew_id = $tripulante->crew_id;

                    // 2. Verificar si YA EXISTE una marcación para este tripulante en esta fecha
                    $marcacionExistente = Marcacion::where('crew_id', $crew_id)
                        ->where('fecha_marcacion', $fechaMarcacion)
                        ->exists();

                    // Solo procesar planificación si NO existe marcación previa
                    if (!$marcacionExistente && $tripulante->iata_aerolinea) {
                        // 3. Buscar planificación válida
                        $planificacion = Planificacion::where('crew_id', $tripulante->crew_id)
                            ->where('iata_aerolinea', $tripulante->iata_aerolinea)
                            ->where('fecha_vuelo', $fechaMarcacion)
                            ->where('estatus', 'P')
                            ->first();

                        if ($planificacion) {
                            // Marcación válida: actualizar planificación y guardar su ID
                            $id_planificacion = $planificacion->id;
                            $planificacion->estatus = 'R';
                            $planificacion->save();
                        }
                    }
                }

                // 4. Obtener lugar_marcacion
                $deviceSn = $proFxAttLog->DEVICE_SN;
                $lugarMarcacion = $deviceSn; // Valor por defecto

                $deviceInfo = ProFxDeviceInfo::where('DEVICE_SN', $deviceSn)->first();
                if ($deviceInfo) {
                    $lugarMarcacion = $deviceInfo->DEVICE_ID;
                }

                // 5. SIEMPRE insertar la marcación (punto clave del requerimiento)
                $marcacion = new Marcacion();
                $marcacion->id_planificacion = $id_planificacion; // Será 0 si no hay planificación válida
                $marcacion->crew_id = $crew_id;
                $marcacion->fecha_marcacion = $fechaMarcacion;
                $marcacion->hora_marcacion = $horaMarcacion;
                $marcacion->lugar_marcacion = $lugarMarcacion;
                $marcacion->save();

            } catch (\Exception $e) {
                Log::error("Error al procesar marcación: {$e->getMessage()}");
            }
        }
    }
}