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
                $id_tripulante = intval($proFxAttLog->USER_PIN);
                $tiempoMarcacionCompleto = Carbon::parse($proFxAttLog->VERIFY_TIME);
                $fechaMarcacion = $tiempoMarcacionCompleto->toDateString();
                $horaMarcacion = $tiempoMarcacionCompleto->toTimeString(); // HH:MM:SS

                Log::info("Procesando marcación para Tripulante ID: {$id_tripulante} en Fecha: {$fechaMarcacion} Hora: {$horaMarcacion}");

                // 1. Buscar la planificación correspondiente
                /** @var Planificacion $planificacionDelDia */
                $planificacionDelDia = Planificacion::where('id_tripulante', $id_tripulante)
                                        ->where('fecha_vuelo', $fechaMarcacion)
                                        ->first();

                if (!$planificacionDelDia) {
                    Log::warning("No se encontró planificación para Tripulante ID: {$id_tripulante} en Fecha: {$fechaMarcacion}. Marcación ignorada.");
                    continue; // Saltar esta marcación
                }

                Log::info("Planificación encontrada ID: {$planificacionDelDia->id}, Hora Vuelo: {$planificacionDelDia->hora_vuelo}");

                // 2. Validar si se permite la marcación según la hora_vuelo
                $horaVueloPlanificada = Carbon::parse($planificacionDelDia->fecha_vuelo . ' ' . $planificacionDelDia->hora_vuelo);

                $puedeMarcar = false;
                if ($tiempoMarcacionCompleto->lessThanOrEqualTo($horaVueloPlanificada)) {
                    // Marcó antes o justo a la hora del vuelo
                    $puedeMarcar = true;
                    Log::info("Marcación ANTES o EN la hora del vuelo. Permitida.");
                } else {
                    // Marcó después de la hora del vuelo
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

                // 3. Si se permite, guardar la marcación
                $lugar_marcacion = substr($proFxAttLog->DEVICE_SN, 0, 10);

                /** @var Marcacion $marcacion */
                $marcacion = new Marcacion();
                $marcacion->id_planificacion = $planificacionDelDia->id; // PK de la tabla planificacion
                $marcacion->id_tripulante = $id_tripulante;
                $marcacion->fecha_marcacion = $fechaMarcacion;
                $marcacion->hora_marcacion = $horaMarcacion; // Esto guardará HH:MM:SS
                $marcacion->lugar_marcacion = $lugar_marcacion;

                $marcacion->save();

                Log::info('Registro de marcacion exitoso: ' . $marcacion->toJson());

            } catch (\Exception $e) {
                Log::error("Error al registrar marcación para USER_PIN: {$proFxAttLog->USER_PIN} en {$proFxAttLog->VERIFY_TIME}. Error: {$e->getMessage()} - Stack: " . $e->getTraceAsString());
            }
        }
    }
}