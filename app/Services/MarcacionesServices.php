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
     * ACTUALIZADO: Implementa la misma lógica de Python para entrada/salida
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

                if (!$tripulante || !$tripulante->crew_id || !$tripulante->iata_aerolinea) {
                    Log::warning("Tripulante no encontrado o datos incompletos para USER_PIN: {$id_tripulante_from_pin}");
                    continue;
                }

                $crew_id_del_tripulante = $tripulante->crew_id;

                // ✅ NUEVA LÓGICA: Verificar marcaciones existentes para hoy (IGUAL QUE PYTHON)
                $marcacionExistente = Marcacion::where('id_tripulante', $id_tripulante_from_pin)
                    ->whereDate('fecha_marcacion', $fechaMarcacionActual)
                    ->first();

                // ✅ DETERMINAR TIPO DE MARCACIÓN (MISMA LÓGICA QUE PYTHON)
                if ($marcacionExistente) {
                    // Ya existe marcación, determinar si es entrada o salida
                    if ($marcacionExistente->hora_entrada && !$marcacionExistente->hora_salida) {
                        // Ya tiene entrada, esta será salida
                        $tipoMarcacion = 2;
                        $procesado = '1'; // Marcar como procesado cuando es salida
                    } else {
                        // Ya tiene ambas, actualizar salida
                        $tipoMarcacion = 2;
                        $procesado = '1';
                    }
                } else {
                    // Primera marcación del día
                    $tipoMarcacion = 1;
                    $procesado = '0'; // No procesado hasta que haya salida
                }

                // Buscar planificación pendiente SOLO si no existe marcación previa con planificación
                if (!$marcacionExistente || $marcacionExistente->id_planificacion == 0) {
                    $planificacionPendiente = Planificacion::where('crew_id', $crew_id_del_tripulante)
                        ->where('iata_aerolinea', $tripulante->iata_aerolinea)
                        ->whereDate('fecha_vuelo', $fechaMarcacionActual)
                        ->where('estatus', 'P')
                        ->first();

                    if ($planificacionPendiente) {
                        $id_planificacion_para_esta_marcacion = $planificacionPendiente->id;

                        // ✅ CAMBIAR ESTATUS SOLO CUANDO ES SALIDA (IGUAL QUE PYTHON)
                        if ($tipoMarcacion == 2) {
                            $planificacionPendiente->estatus = 'R';
                            $planificacionPendiente->save();
                            Log::info("Estatus de planificación {$planificacionPendiente->id} actualizado a 'R'");
                        }
                    }
                } else {
                    // Usar la planificación existente de la marcación previa
                    $id_planificacion_para_esta_marcacion = $marcacionExistente->id_planificacion;

                    // Si es salida y hay planificación, marcar como procesada
                    if ($tipoMarcacion == 2 && $id_planificacion_para_esta_marcacion > 0) {
                        $planificacion = Planificacion::find($id_planificacion_para_esta_marcacion);
                        if ($planificacion && $planificacion->estatus == 'P') {
                            $planificacion->estatus = 'R';
                            $planificacion->save();
                            Log::info("Estatus de planificación {$planificacion->id} actualizado a 'R'");
                        }
                    }
                }

                // Obtención del lugar de marcación y punto de control
                $deviceSn = $proFxAttLog->DEVICE_SN;
                $lugarMarcacionDeterminado = $deviceSn;
                $deviceId = null;

                $deviceInfo = ProFxDeviceInfo::where('DEVICE_SN', $deviceSn)->first();
                if ($deviceInfo && $deviceInfo->DEVICE_ID) {
                    $deviceId = $deviceInfo->DEVICE_ID;
                    $lugarMarcacionDeterminado = $deviceId;

                    $dispositivoPunto = DB::table('dispositivos_puntos')
                        ->where('id_device', $deviceId)
                        ->first();

                    if ($dispositivoPunto) {
                        $punto_control = $dispositivoPunto->id_punto;
                    }
                }

                // ✅ GUARDAR O ACTUALIZAR MARCACIÓN (MISMA LÓGICA QUE PYTHON)
                if ($marcacionExistente) {
                    // Actualizar marcación existente
                    $marcacionExistente->update([
                        'id_planificacion' => $id_planificacion_para_esta_marcacion ?: $marcacionExistente->id_planificacion,
                        'hora_entrada' => $tipoMarcacion == 1 ? $horaMarcacionActual : $marcacionExistente->hora_entrada,
                        'hora_salida' => $tipoMarcacion == 2 ? $horaMarcacionActual : $marcacionExistente->hora_salida,
                        'hora_marcacion' => $horaMarcacionActual,
                        'lugar_marcacion' => $lugarMarcacionDeterminado,
                        'punto_control' => $punto_control,
                        'procesado' => $procesado,
                        'tipo_marcacion' => $tipoMarcacion,
                        'usuario' => 'zkteco_system'
                    ]);

                    Log::info("Marcación actualizada - Tripulante: {$crew_id_del_tripulante}, Tipo: {$tipoMarcacion}, Procesado: {$procesado}");
                } else {
                    // Crear nueva marcación
                    $marcacion = new Marcacion();
                    $marcacion->id_planificacion = $id_planificacion_para_esta_marcacion;
                    $marcacion->id_tripulante = $id_tripulante_from_pin;
                    $marcacion->crew_id = $crew_id_del_tripulante;
                    $marcacion->fecha_marcacion = $fechaMarcacionActual;
                    $marcacion->hora_entrada = $tipoMarcacion == 1 ? $horaMarcacionActual : null;
                    $marcacion->hora_salida = $tipoMarcacion == 2 ? $horaMarcacionActual : null;
                    $marcacion->hora_marcacion = $horaMarcacionActual;
                    $marcacion->lugar_marcacion = $lugarMarcacionDeterminado;
                    $marcacion->punto_control = $punto_control;
                    $marcacion->procesado = $procesado;
                    $marcacion->tipo_marcacion = $tipoMarcacion;
                    $marcacion->usuario = 'zkteco_system';
                    $marcacion->save();

                    Log::info("Nueva marcación creada - Tripulante: {$crew_id_del_tripulante}, Tipo: {$tipoMarcacion}, Procesado: {$procesado}");
                }

            } catch (\Exception $e) {
                Log::error("Error al procesar marcación para USER_PIN: {$proFxAttLog->USER_PIN}", [
                    'mensaje' => $e->getMessage(),
                    'stacktrace' => $e->getTraceAsString()
                ]);
                continue;
            }
        }
    }
}
