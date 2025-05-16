<?php

namespace App\Services;

use App\Models\Marcacion;
use App\Models\Planificacion; // Asegúrate de importar tu modelo Planificacion
use App\Models\ZKTeco\ProFaceX\ProFxAttLog;
// use App\Models\ZKTeco\ProFaceX\ProFxDeviceInfo; // Solo si aún lo necesitas para algo más
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class MarcacionesServices
{
    /** @param ProFxAttLog[] $proFxAttLogList */
    public function registrarMarcaciones(array $proFxAttLogList)
    {
        foreach ($proFxAttLogList as $proFxAttLog) {
            try {
                $crew_id = $proFxAttLog->USER_PIN;
                $tiempoMarcacionCompleto = Carbon::parse($proFxAttLog->VERIFY_TIME);
                $fechaMarcacion = $tiempoMarcacionCompleto->toDateString();
                $horaMarcacion = $tiempoMarcacionCompleto->toTimeString(); // HH:MM:SS

                // Asumimos que el objeto trae iata_aerolinea y numero_vuelo
                $iata_aerolinea = $proFxAttLog->iata_aerolinea ?? null;
                $numero_vuelo = $proFxAttLog->numero_vuelo ?? null;

                Log::info("Procesando marcación para Crew ID: {$crew_id} Aerolínea: {$iata_aerolinea} en Fecha: {$fechaMarcacion} Hora: {$horaMarcacion}");

                if (!$iata_aerolinea || !$numero_vuelo) {
                    Log::warning("Faltan datos de iata_aerolinea o numero_vuelo en el log. Marcación ignorada.");
                    continue;
                }

                // 1. Buscar la planificación correspondiente
                $planificacion = Planificacion::where('iata_aerolinea', $iata_aerolinea)
                    ->where('crew_id', $crew_id)
                    ->where('fecha_vuelo', $fechaMarcacion)
                    ->where('numero_vuelo', $numero_vuelo)
                    ->where('estatus', 'P')
                    ->first();

                if (!$planificacion) {
                    Log::warning("No se encontró planificación para Crew ID: {$crew_id}, Aerolínea: {$iata_aerolinea}, Fecha: {$fechaMarcacion}, Vuelo: {$numero_vuelo}. Marcación ignorada.");
                    continue;
                }

                // 2. Buscar el tripulante
                $tripulante = \App\Models\Tripulante::where('iata_aerolinea', $iata_aerolinea)
                    ->where('crew_id', $crew_id)
                    ->first();

                if (!$tripulante) {
                    Log::warning("No se encontró tripulante para Crew ID: {$crew_id}, Aerolínea: {$iata_aerolinea}. Marcación ignorada.");
                    continue;
                }

                // 3. Validar si se permite la marcación según la hora_vuelo
                $horaVueloPlanificada = Carbon::parse($planificacion->fecha_vuelo . ' ' . $planificacion->hora_vuelo);
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

                Log::info('Planificación actualizada a estatus R: ' . $planificacion->toJson());

            } catch (\Exception $e) {
                Log::error("Error al procesar marcación para Crew ID: {$crew_id} en {$proFxAttLog->VERIFY_TIME}. Error: {$e->getMessage()} - Stack: " . $e->getTraceAsString());
            }
        }
    }
}