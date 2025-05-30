<?php

namespace App\Services;

use App\Models\Marcacion;
use App\Models\Planificacion;
use App\Models\Tripulante;
use App\Models\ZKTeco\ProFaceX\ProFxAttLog;
use App\Models\ZKTeco\ProFaceX\ProFxDeviceInfo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MarcacionesServices
{
    /**
     * Registra marcaciones y las asocia con planificaciones según reglas específicas
     *
     * @param array $proFxAttLogList Lista de objetos ProFxAttLog a procesar
     * @return void
     */
    public function registrarMarcaciones(array $proFxAttLogList)
    {
        foreach ($proFxAttLogList as $proFxAttLog) {
            try {
                // Extracción y preparación de datos iniciales
                $id_tripulante_from_pin = (int) $proFxAttLog->USER_PIN;
                $tiempoMarcacionCompleto = Carbon::parse($proFxAttLog->VERIFY_TIME);
                $fechaMarcacionActual = $tiempoMarcacionCompleto->format('Y-m-d');
                $horaMarcacionActual = $tiempoMarcacionCompleto->format('H:i:s');

                // Valores por defecto
                $id_planificacion_para_esta_marcacion = 0;
                $crew_id_del_tripulante = null;
                $punto_control = null;

                // Obtención de datos del tripulante
                $tripulante = Tripulante::where('id_tripulante', $id_tripulante_from_pin)->first();

                if ($tripulante && $tripulante->crew_id && $tripulante->iata_aerolinea) {
                    $crew_id_del_tripulante = $tripulante->crew_id;

                    // Verificar si ya existe una marcación previa con planificación asignada
                    $marcacionPreviaConPlanificacion = Marcacion::where('id_tripulante', $id_tripulante_from_pin)
                        ->where('fecha_marcacion', $fechaMarcacionActual)
                        ->where('id_planificacion', '>', 0)
                        ->exists();

                    // Solo buscar y asignar planificación si NO existe marcación previa con planificación
                    if (!$marcacionPreviaConPlanificacion) {
                        // Buscar planificación pendiente
                        $planificacionPendiente = Planificacion::where('crew_id', $crew_id_del_tripulante)
                            ->where('iata_aerolinea', $tripulante->iata_aerolinea)
                            ->where('fecha_vuelo', $fechaMarcacionActual)
                            ->where('estatus', 'P')
                            ->first();

                        if ($planificacionPendiente) {
                            // Asignar planificación y cambiar estatus
                            $id_planificacion_para_esta_marcacion = $planificacionPendiente->id;
                            $planificacionPendiente->estatus = 'R';
                            $planificacionPendiente->save();
                        }
                    }
                }

                // Obtención del lugar de marcación y punto de control
                $deviceSn = $proFxAttLog->DEVICE_SN;
                $lugarMarcacionDeterminado = $deviceSn; // Valor por defecto
                $deviceId = null;

                $deviceInfo = ProFxDeviceInfo::where('DEVICE_SN', $deviceSn)->first();
                if ($deviceInfo && $deviceInfo->DEVICE_ID) {
                    $deviceId = $deviceInfo->DEVICE_ID;
                    $lugarMarcacionDeterminado = $deviceId;

                    // Buscar el punto de control asociado al dispositivo
                    $dispositivoPunto = DB::table('dispositivos_puntos')
                        ->where('id_device', $deviceId)
                        ->first();

                    if ($dispositivoPunto) {
                        $punto_control = $dispositivoPunto->id_punto;
                    }
                }

                // Guardar la nueva marcación
                $marcacion = new Marcacion();
                $marcacion->id_planificacion = $id_planificacion_para_esta_marcacion;
                $marcacion->id_tripulante = $id_tripulante_from_pin;
                $marcacion->crew_id = $crew_id_del_tripulante;
                $marcacion->fecha_marcacion = $fechaMarcacionActual;
                $marcacion->hora_marcacion = $horaMarcacionActual;
                $marcacion->lugar_marcacion = $lugarMarcacionDeterminado;
                $marcacion->punto_control = $punto_control;
                $marcacion->save();

            } catch (\Exception $e) {
                Log::error("Error al procesar marcación para USER_PIN: {$proFxAttLog->USER_PIN}", [
                    'mensaje' => $e->getMessage(),
                    'stacktrace' => $e->getTraceAsString()
                ]);
                continue; // Continuar con el siguiente registro
            }
        }
    }
}