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

                // 1. Buscar el tripulante
                $tripulante = \App\Models\Tripulante::where('id_tripulante', $id_tripulante)->first();

                if (!$tripulante || !$tripulante->crew_id || !$tripulante->iata_aerolinea) {
                    Log::warning("Tripulante ID: {$id_tripulante} no encontrado o faltan datos. Marcación ignorada.");
                    continue;
                }

                // 2. Buscar la planificación correspondiente
                $planificacion = Planificacion::where('crew_id', $tripulante->crew_id)
                    ->where('iata_aerolinea', $tripulante->iata_aerolinea)
                    ->where('fecha_vuelo', $fechaMarcacion)
                    ->where('estatus', 'P')
                    ->first();

                if (!$planificacion) {
                    Log::warning("No se encontró planificación para crew_id: {$tripulante->crew_id}, fecha: {$fechaMarcacion}. Marcación ignorada.");
                    continue;
                }

                // 3. Validar tiempo de marcación
                $fechaSoloString = Carbon::parse($planificacion->fecha_vuelo)->format('Y-m-d');
                $horaVueloPlanificada = Carbon::parse($fechaSoloString . ' ' . $planificacion->hora_vuelo);

                if ($tiempoMarcacionCompleto->gt($horaVueloPlanificada) &&
                    $tiempoMarcacionCompleto->diffInHours($horaVueloPlanificada) > 2) {
                    Log::warning("Marcación fuera de tiempo permitido para vuelo ID: {$planificacion->id}");
                    continue;
                }

                // 4. Actualizar estatus de planificación
                $planificacion->estatus = 'R';
                $planificacion->save();

                // 5. Obtener DEVICE_ID a partir del DEVICE_SN
                $deviceSn = $proFxAttLog->DEVICE_SN;
                $lugarMarcacion = $deviceSn; // Valor por defecto en caso de no encontrar el dispositivo

                // Buscar el dispositivo por DEVICE_SN para obtener el DEVICE_ID
                $deviceInfo = ProFxDeviceInfo::where('DEVICE_SN', $deviceSn)->first();
                if ($deviceInfo) {
                    $lugarMarcacion = $deviceInfo->DEVICE_ID;
                }

                // 6. Insertar marcación
                $marcacion = new Marcacion();
                $marcacion->id_planificacion = $planificacion->id;
                $marcacion->crew_id = $tripulante->crew_id;
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