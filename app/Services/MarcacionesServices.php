<?php

namespace App\Services;

use App\Models\Marcacion;
use App\Models\Planificacion; // Asegúrate de importar tu modelo Planificacion
use App\Models\ZKTeco\ProFaceX\ProFxAttLog;
use App\Models\ZKTeco\ProFaceX\ProFxDeviceInfo; // Importamos el modelo ProFxDeviceInfo
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class MarcacionesServices
{
    /** @param ProFxAttLog[] $proFxAttLogList */
    public function registrarMarcaciones(array $proFxAttLogList)
    {
        foreach ($proFxAttLogList as $proFxAttLog) {
            try {
                // USER_PIN es el id_tripulante
                $id_tripulante = intval($proFxAttLog->USER_PIN);
                $tiempoMarcacionCompleto = Carbon::parse($proFxAttLog->VERIFY_TIME);
                $fechaMarcacion = $tiempoMarcacionCompleto->toDateString();
                $horaMarcacion = $tiempoMarcacionCompleto->toTimeString(); // HH:MM:SS

                Log::info("Procesando marcación para Tripulante ID: {$id_tripulante} en Fecha: {$fechaMarcacion} Hora: {$horaMarcacion}");

                // 1. Buscar el tripulante para obtener crew_id e iata_aerolinea
                $tripulante = \App\Models\Tripulante::where('id_tripulante', $id_tripulante)->first();

                if (!$tripulante) {
                    Log::warning("No se encontró tripulante con ID: {$id_tripulante}. Marcación ignorada.");
                    continue;
                }

                // Verificar que el tripulante tenga crew_id e iata_aerolinea
                if (!$tripulante->crew_id || !$tripulante->iata_aerolinea) {
                    Log::warning("El tripulante ID: {$id_tripulante} no tiene crew_id o iata_aerolinea. Marcación ignorada.");
                    continue;
                }

                // 2. Buscar la planificación correspondiente usando crew_id, iata_aerolinea y fecha_vuelo
                $planificacion = Planificacion::where('crew_id', $tripulante->crew_id)
                    ->where('iata_aerolinea', $tripulante->iata_aerolinea)
                    ->where('fecha_vuelo', $fechaMarcacion)
                    ->where('estatus', 'P')
                    ->first();

                if (!$planificacion) {
                    Log::warning("No se encontró planificación con crew_id: {$tripulante->crew_id}, iata_aerolinea: {$tripulante->iata_aerolinea}, fecha: {$fechaMarcacion}, estatus: P. Marcación ignorada.");
                    continue;
                }

                Log::info("Planificación encontrada ID: {$planificacion->id}, Vuelo: {$planificacion->numero_vuelo}, Hora: {$planificacion->hora_vuelo}");

                // 3. Validar si se permite la marcación según la hora_vuelo
                $fechaSoloString = Carbon::parse($planificacion->fecha_vuelo)->format('Y-m-d');
                $horaVueloPlanificada = Carbon::parse($fechaSoloString . ' ' . $planificacion->hora_vuelo);

                $puedeMarcar = false;

                if ($tiempoMarcacionCompleto->lessThanOrEqualTo($horaVueloPlanificada)) {
                    $puedeMarcar = true;
                    Log::info("Marcación ANTES o EN la hora del vuelo. Permitida.");
                } else {
                    $diferenciaHoras = $tiempoMarcacionCompleto->diffInHours($horaVueloPlanificada);
                    if ($diferenciaHoras <= 2) {
                        $puedeMarcar = true;
                        Log::info("Marcación DESPUÉS de la hora del vuelo (dentro de 2 horas). Permitida. Diferencia: {$diferenciaHoras}h");
                    } else {
                        Log::warning("Marcación DESPUÉS de la hora del vuelo (fuera de 2 horas). NO Permitida. Diferencia: {$diferenciaHoras}h");
                    }
                }

                if (!$puedeMarcar) {
                    continue; // Saltar esta marcación porque no cumple la regla de tiempo
                }

                // 4. Cambiar el estatus de la planificación a 'R'
                $planificacion->estatus = 'R';
                $planificacion->save();

                Log::info("Planificación ID: {$planificacion->id} actualizada a estatus 'R'");

                // 5. Obtener información del dispositivo/lugar de marcación
                $deviceId = $proFxAttLog->DEVICE_ID;
                $lugarMarcacion = $deviceId; // Por defecto usamos el DEVICE_ID

                // Intentar obtener más información del dispositivo
                try {
                    $deviceInfo = ProFxDeviceInfo::find($deviceId);
                    if ($deviceInfo) {
                        $lugarMarcacion = $deviceInfo->DEVICE_SN ?? $deviceInfo->LOCATION ?? $deviceId;
                        Log::info("Información de dispositivo encontrada: {$lugarMarcacion}");
                    } else {
                        Log::info("No se encontró información adicional para el dispositivo ID: {$deviceId}");
                    }
                } catch (\Exception $e) {
                    Log::warning("Error al buscar información del dispositivo: {$e->getMessage()}");
                }

                // 6. Insertar en la tabla marcacion
                $marcacion = new Marcacion();
                $marcacion->id_planificacion = $planificacion->id;
                $marcacion->crew_id = $tripulante->crew_id;
                $marcacion->fecha_marcacion = $fechaMarcacion;
                $marcacion->hora_marcacion = $horaMarcacion;
                $marcacion->lugar_marcacion = $lugarMarcacion; // Guardamos el lugar de marcación
                $marcacion->save();

                Log::info("Marcación guardada con ID: {$marcacion->id_marcacion}, Lugar: {$lugarMarcacion}");

            } catch (\Exception $e) {
                Log::error("Error al procesar marcación para ID Tripulante: {$proFxAttLog->USER_PIN} en {$proFxAttLog->VERIFY_TIME}. Error: {$e->getMessage()} - Stack: " . $e->getTraceAsString());
            }
        }
    }
}