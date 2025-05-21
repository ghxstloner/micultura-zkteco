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
                $id_planificacion = 0; // Por defecto sin planificación
                $crew_id = null;

                // 1. Buscar el tripulante
                $tripulante = \App\Models\Tripulante::where('id_tripulante', $id_tripulante)->first();

                if ($tripulante && $tripulante->crew_id) {
                    $crew_id = $tripulante->crew_id;

                    // 2. Buscar si hay una planificación activa para la fecha actual
                    if ($tripulante->iata_aerolinea) {
                        // Buscar planificación con estatus P (pendiente) o R (realizada)
                        $planificacion = Planificacion::where('crew_id', $tripulante->crew_id)
                            ->where('iata_aerolinea', $tripulante->iata_aerolinea)
                            ->where('fecha_vuelo', $fechaMarcacion)
                            ->whereIn('estatus', ['P', 'R'])
                            ->first();

                        if ($planificacion) {
                            $id_planificacion = $planificacion->id;

                            // Actualizar el estatus solo si está en 'P'
                            if ($planificacion->estatus === 'P') {
                                $planificacion->estatus = 'R';
                                $planificacion->save();
                            }
                        }
                    }
                }

                // 3. Obtener lugar_marcacion
                $deviceSn = $proFxAttLog->DEVICE_SN;
                $lugarMarcacion = $deviceSn;

                $deviceInfo = ProFxDeviceInfo::where('DEVICE_SN', $deviceSn)->first();
                if ($deviceInfo) {
                    $lugarMarcacion = $deviceInfo->DEVICE_ID;
                }

                // 4. SIEMPRE insertar una nueva marcación
                $marcacion = new Marcacion();
                $marcacion->id_planificacion = $id_planificacion;
                $marcacion->crew_id = $crew_id;
                $marcacion->id_tripulante = $id_tripulante;
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