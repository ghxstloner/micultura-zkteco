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
     * Registra marcaciones y las asocia con planificaciones según reglas específicas.
     * Implementa lógica de entrada/salida y logs detallados para depuración.
     *
     * @param array $proFxAttLogList Lista de objetos ProFxAttLog a procesar
     * @return void
     */
    public function registrarMarcaciones(array $proFxAttLogList)
    {
        Log::info("--- INICIO DEL PROCESO DE MARCACIONES ---");
        Log::info("Se van a procesar " . count($proFxAttLogList) . " registros de ZKTeco.");

        foreach ($proFxAttLogList as $index => $proFxAttLog) {
            $id_tripulante_from_pin = (int) $proFxAttLog->USER_PIN;
            Log::info("--- Procesando registro " . ($index + 1) . " para USER_PIN: {$id_tripulante_from_pin} ---");

            try {
                // 1. Extracción y preparación de datos iniciales
                $tiempoMarcacionCompleto = Carbon::parse($proFxAttLog->VERIFY_TIME);
                $fechaMarcacionActual = $tiempoMarcacionCompleto->format('Y-m-d');
                $horaMarcacionActual = $tiempoMarcacionCompleto->format('H:i:s');
                Log::info("Datos de marcación: Fecha='{$fechaMarcacionActual}', Hora='{$horaMarcacionActual}'");

                // 2. Obtención de datos del tripulante
                $tripulante = Tripulante::where('id_tripulante', $id_tripulante_from_pin)->first();

                if (!$tripulante) {
                    Log::warning("Tripulante con USER_PIN: {$id_tripulante_from_pin} NO ENCONTRADO en la base de datos. Saltando registro.");
                    continue;
                }

                if (!$tripulante->crew_id || !$tripulante->iata_aerolinea) {
                    Log::warning("Tripulante con USER_PIN: {$id_tripulante_from_pin} tiene datos incompletos (crew_id o iata_aerolinea). Saltando registro.");
                    continue;
                }

                $crew_id_del_tripulante = $tripulante->crew_id;
                Log::info("Tripulante encontrado: ID={$tripulante->id_tripulante}, CrewID='{$crew_id_del_tripulante}'");

                // 3. Verificar marcaciones existentes para hoy
                $marcacionExistente = Marcacion::where('id_tripulante', $id_tripulante_from_pin)
                    ->whereDate('fecha_marcacion', $fechaMarcacionActual)
                    ->first();

                // 4. Determinar tipo de marcación (Entrada o Salida)
                $tipoMarcacion = 1; // Por defecto es Entrada
                $procesado = '0';   // Por defecto no procesado

                if ($marcacionExistente) {
                    Log::info("Se encontró una marcación existente para hoy (ID: {$marcacionExistente->id_marcacion}).");
                    if ($marcacionExistente->hora_entrada && !$marcacionExistente->hora_salida) {
                        $tipoMarcacion = 2; // Ya tiene entrada, esta es Salida
                        $procesado = '1';
                        Log::info("La marcación existente tiene ENTRADA pero no SALIDA. Esta nueva marcación será de tipo SALIDA.");
                    } else {
                        $tipoMarcacion = 2; // Ya tiene ambas, se considera actualización de Salida
                        $procesado = '1';
                        Log::info("La marcación existente ya tiene ENTRADA y SALIDA. Se actualizará la SALIDA.");
                    }
                } else {
                    Log::info("No se encontró marcación previa para hoy. Esta será una marcación de tipo ENTRADA.");
                }

                // 5. Buscar planificación asociada
                $id_planificacion_para_esta_marcacion = 0;

                // Si la marcación ya existe y tiene una planificación, la reutilizamos.
                if ($marcacionExistente && $marcacionExistente->id_planificacion > 0) {
                    $id_planificacion_para_esta_marcacion = $marcacionExistente->id_planificacion;
                    Log::info("Reutilizando id_planificacion de la marcación existente: {$id_planificacion_para_esta_marcacion}");
                } else {
                    // Si no, buscamos una planificación pendiente
                    Log::info("Buscando planificación pendiente para CrewID='{$crew_id_del_tripulante}' en fecha '{$fechaMarcacionActual}'");
                    $planificacionPendiente = Planificacion::where('crew_id', $crew_id_del_tripulante)
                        ->where('iata_aerolinea', $tripulante->iata_aerolinea)
                        ->whereDate('fecha_vuelo', $fechaMarcacionActual)
                        ->where('estatus', 'P')
                        ->first();

                    if ($planificacionPendiente) {
                        $id_planificacion_para_esta_marcacion = $planificacionPendiente->id;
                        Log::info("Planificación PENDIENTE encontrada (ID: {$planificacionPendiente->id}).");
                    } else {
                        Log::warning("No se encontró ninguna planificación PENDIENTE para CrewID='{$crew_id_del_tripulante}' en la fecha '{$fechaMarcacionActual}'. La marcación se guardará sin planificación asociada.");
                    }
                }

                // 6. Obtención del lugar de marcación
                $deviceSn = $proFxAttLog->DEVICE_SN;
                $punto_control = null;
                $lugarMarcacionDeterminado = $deviceSn; // Valor por defecto
                $deviceInfo = ProFxDeviceInfo::where('DEVICE_SN', $deviceSn)->first();

                if ($deviceInfo && $deviceInfo->DEVICE_ID) {
                    $lugarMarcacionDeterminado = $deviceInfo->DEVICE_ID;
                    $dispositivoPunto = DB::table('dispositivos_puntos')->where('id_device', $deviceInfo->DEVICE_ID)->first();
                    if ($dispositivoPunto) {
                        $punto_control = $dispositivoPunto->id_punto;
                    }
                }
                Log::info("Lugar de marcación determinado: '{$lugarMarcacionDeterminado}', Punto de control: '{$punto_control}'");

                // 7. Guardar o Actualizar Marcación
                $datosMarcacion = [
                    'id_planificacion' => $id_planificacion_para_esta_marcacion,
                    'hora_marcacion' => $horaMarcacionActual,
                    'lugar_marcacion' => $lugarMarcacionDeterminado,
                    'punto_control' => $punto_control,
                    'procesado' => $procesado,
                    'tipo_marcacion' => $tipoMarcacion,
                    'usuario' => 'zkteco_system'
                ];

                if ($marcacionExistente) {
                    // --- ACTUALIZAR ---
                    $datosMarcacion['hora_salida'] = $tipoMarcacion == 2 ? $horaMarcacionActual : $marcacionExistente->hora_salida;

                    Log::info("Intentando ACTUALIZAR marcacion ID: {$marcacionExistente->id_marcacion} con los siguientes datos:", $datosMarcacion);
                    $resultado = $marcacionExistente->update($datosMarcacion);

                    if ($resultado) {
                        Log::info("¡ÉXITO! Marcación actualizada correctamente.");
                    } else {
                        Log::error("¡FALLO! La actualización de la marcación ID: {$marcacionExistente->id_marcacion} no tuvo éxito. Revisa el modelo 'Marcacion' y la propiedad '\$fillable'.");
                    }
                } else {
                    // --- CREAR ---
                    $datosMarcacion['id_tripulante'] = $id_tripulante_from_pin;
                    $datosMarcacion['crew_id'] = $crew_id_del_tripulante;
                    $datosMarcacion['fecha_marcacion'] = $fechaMarcacionActual;
                    $datosMarcacion['hora_entrada'] = $horaMarcacionActual;
                    $datosMarcacion['hora_salida'] = null;

                    Log::info("Intentando CREAR nueva marcación con los siguientes datos:", $datosMarcacion);
                    $marcacionNueva = new Marcacion($datosMarcacion);
                    $resultado = $marcacionNueva->save();

                    if ($resultado) {
                        Log::info("¡ÉXITO! Nueva marcación creada con ID: {$marcacionNueva->id_marcacion}");
                    } else {
                        Log::error("¡FALLO! La creación de la nueva marcación no tuvo éxito.");
                    }
                }

                // 8. Actualizar estatus de planificación si es marcación de salida y hay planificación
                if ($tipoMarcacion == 2 && $id_planificacion_para_esta_marcacion > 0) {
                    $planificacionParaActualizar = Planificacion::find($id_planificacion_para_esta_marcacion);
                    if ($planificacionParaActualizar && $planificacionParaActualizar->estatus == 'P') {
                        $planificacionParaActualizar->estatus = 'R'; // Realizado
                        $planificacionParaActualizar->save();
                        Log::info("Estatus de planificación {$planificacionParaActualizar->id} actualizado a 'R'.");
                    }
                }

            } catch (\Exception $e) {
                Log::error("--- ERROR GRAVE al procesar marcación para USER_PIN: {$id_tripulante_from_pin} ---", [
                    'mensaje' => $e->getMessage(),
                    'archivo' => $e->getFile(),
                    'linea' => $e->getLine(),
                    'stacktrace' => $e->getTraceAsString()
                ]);
                continue; // Saltar al siguiente registro en caso de error
            }
        }
        Log::info("--- FIN DEL PROCESO DE MARCACIONES ---");
    }
}
