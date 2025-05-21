<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ZKTeco\ProFaceX\ProFxDeviceInfo;
use App\Models\ZKTeco\ProFaceX\ProFxUserInfo;
use App\Models\ZKTeco\ProFaceX\ProFxAdvInfo;
use App\Models\ZKTeco\ProFaceX\ProFxAttLog;
use App\Models\ZKTeco\ProFaceX\ProFxAttPhoto;
use App\Models\ZKTeco\ProFaceX\ProFxDeviceAttrs;
use App\Models\ZKTeco\ProFaceX\ProFxMeetInfo;
use App\Models\ZKTeco\ProFaceX\ProFxMessage;
use App\Models\ZKTeco\ProFaceX\ProFxPersBioTemplate;
use App\Services\ZKTeco\ProFaceX\Manager\ManagerFactory;
use App\Services\ZKTeco\ProFaceX\DevCmdUtil;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class TripulanteApiController extends Controller
{
    /**
     * Recibe datos de una persona y los envía a los dispositivos ZKTeco.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        // Validar los datos de entrada
        $validator = Validator::make($request->all(), [
            'id_tripulante' => 'required|string|max:255', // ID único para el dispositivo
            'nombres' => 'required|string|max:100',
            'apellidos' => 'required|string|max:100',
            'foto' => 'nullable|image|mimes:jpeg,png,jpg|max:2048', // Foto como archivo, opcional
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        try {
            $fotoBase64 = null;
            $fotoSize = null;
            $fotoNombreParaDispositivo = null;

            if ($request->hasFile('foto')) {
                $foto = $request->file('foto');
                $fotoContent = file_get_contents($foto->getRealPath());

                // Comprimir la imagen antes de convertirla a base64
                $compressedImage = $this->compressImage($fotoContent);

                $fotoBase64 = base64_encode($compressedImage);
                $fotoSize = strlen($compressedImage);
                $fotoNombreParaDispositivo = $request->id_tripulante . ".jpg";
            }

            $nombreCompleto = $request->nombres . ' ' . $request->apellidos;

            // Obtener dispositivos activos
            $dispositivosActivos = ProFxDeviceInfo::activos()->get();

            if ($dispositivosActivos->isEmpty()) {
                return response()->json([
                    'warning' => 'No hay dispositivos activos disponibles',
                    'id_tripulante_procesado' => $request->id_tripulante
                ], 200);
            }

            $resultados = [];

            foreach ($dispositivosActivos as $dispositivo) {
                // Obtener el DEV_FUNS actual del dispositivo - IMPORTANTE: Esto faltaba en el método original
                $devFuns = $dispositivo->DEV_FUNS;

                // Buscar o crear el usuario en el dispositivo
                $userInfo = ProFxUserInfo::firstOrNew([
                    'USER_PIN' => $request->id_tripulante,
                    'DEVICE_SN' => $dispositivo->DEVICE_SN
                ]);

                // Rellenar la información del usuario
                $userInfo->NAME = $nombreCompleto;
                $userInfo->MAIN_CARD = $request->id_tripulante; // Importante: se usa en el código original

                if ($fotoBase64 && $fotoSize) {
                    $userInfo->PHOTO_ID_NAME = $fotoNombreParaDispositivo;
                    $userInfo->PHOTO_ID_SIZE = $fotoSize;
                    $userInfo->PHOTO_ID_CONTENT = $fotoBase64;
                }

                // Valores predeterminados según el código original
                $userInfo->PASSWORD = "";
                $userInfo->FACE_GROUP_ID = 0;
                $userInfo->ACC_GROUP_ID = 0;
                $userInfo->DEPT_ID = 0;
                $userInfo->IS_GROUP_TZ = 0;
                $userInfo->VERIFY_TYPE = 0;
                $userInfo->category = 0;
                $userInfo->PRIVILEGE = 0;

                $userInfo->save();

                ManagerFactory::getCommandManager()->createUpdateUserInfosCommandByIds(
                    $userInfo,
                    $devFuns
                );

                // Ejecutar comandos para CADA dispositivo INMEDIATAMENTE - Como en syncDevices
                $this->executeDeviceCommands($dispositivo->DEVICE_SN);

                $resultados[] = [
                    'device_id' => $dispositivo->DEVICE_ID,
                    'device_sn' => $dispositivo->DEVICE_SN,
                    'resultado' => 'Comandos enviados correctamente'
                ];
            }

            return response()->json([
                'message' => 'Datos de persona enviados a dispositivos exitosamente',
                'id_tripulante_procesado' => $request->id_tripulante,
                'resultados_dispositivos' => $resultados
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al procesar y enviar datos de persona',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Sincronizar todos los tripulantes con los dispositivos seleccionados.
     * Sincroniza TODOS los tripulantes que tienen una imagen válida.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function syncDevices(Request $request)
    {
        try {
            // Validar los dispositivos seleccionados
            $validator = Validator::make($request->all(), [
                'device_ids' => 'required|array',
                'device_ids.*' => 'required|integer|exists:profacex_device_info,DEVICE_ID',
            ]);

            if ($validator->fails()) {
                return response()->json(['error' => $validator->errors()], 422);
            }

            $deviceIds = $request->device_ids;

            if (empty($deviceIds)) {
                return response()->json([
                    'error' => 'No se seleccionaron dispositivos para sincronizar.'
                ], 400);
            }

            // Obtener dispositivos seleccionados
            $dispositivos = ProFxDeviceInfo::whereIn('DEVICE_ID', $deviceIds)->get();

            if ($dispositivos->isEmpty()) {
                return response()->json([
                    'error' => 'No se encontraron dispositivos válidos para sincronizar.'
                ], 400);
            }

            // Obtener la URL base desde las variables de entorno
            $baseUrl = env('IMAGEN_URL_BASE');

            // Obtener todos los tripulantes que tienen una imagen asignada - Optimización: seleccionar solo columnas necesarias
            $tripulantes = DB::table('tripulantes')
                ->whereNotNull('imagen')
                ->whereNotNull('iata_aerolinea')
                ->whereNotNull('crew_id')
                ->select('id_tripulante', 'nombres', 'apellidos', 'imagen', 'iata_aerolinea', 'crew_id')
                ->get();

            if ($tripulantes->isEmpty()) {
                return response()->json([
                    'message' => 'No hay tripulantes con imágenes para sincronizar.'
                ], 200);
            }

            $resultadosSincronizacion = [];

            // Para cada dispositivo seleccionado
            foreach ($dispositivos as $dispositivo) {
                $dispositivoResult = [
                    'device_id' => $dispositivo->DEVICE_ID,
                    'device_sn' => $dispositivo->DEVICE_SN,
                    'usuarios_sincronizados' => 0,
                    'usuarios_fallidos' => 0,
                    'detalles' => []
                ];

                // Obtener el DEV_FUNS actual del dispositivo
                $devFuns = $dispositivo->DEV_FUNS;
                $loteUserInfos = [];
                $loteSize = 50; // Procesar en lotes de 50 usuarios

                // Para cada tripulante a sincronizar
                foreach ($tripulantes as $index => $tripulante) {
                    try {
                        // Construir la URL de la imagen
                        $imagenUrl = "{$baseUrl}/{$tripulante->iata_aerolinea}/{$tripulante->crew_id}/{$tripulante->imagen}";

                        // Intentar obtener la imagen y verificar que sea válida
                        $fotoContent = @file_get_contents($imagenUrl);

                        if ($fotoContent === false) {
                            throw new \Exception("No se pudo obtener la imagen");
                        }

                        // Verificar que el contenido sea una imagen válida - Optimización: verificación simplificada
                        $finfo = new \finfo(FILEINFO_MIME_TYPE);
                        $mimeType = $finfo->buffer($fotoContent);

                        if (strpos($mimeType, 'image/') !== 0) {
                            throw new \Exception("El contenido obtenido no es una imagen válida");
                        }

                        // Comprimir la imagen antes de convertirla a base64
                        $compressedImage = $this->compressImage($fotoContent);

                        $fotoBase64 = base64_encode($compressedImage);
                        $fotoSize = strlen($compressedImage);
                        $fotoNombreParaDispositivo = $tripulante->id_tripulante . ".jpg";

                        // Buscar o crear el userInfo en el dispositivo destino
                        $userInfo = ProFxUserInfo::firstOrNew([
                            'USER_PIN' => $tripulante->id_tripulante,
                            'DEVICE_SN' => $dispositivo->DEVICE_SN
                        ]);

                        // Rellenar la información del usuario
                        $userInfo->NAME = $tripulante->nombres . ' ' . $tripulante->apellidos;
                        $userInfo->MAIN_CARD = $tripulante->id_tripulante;
                        $userInfo->PHOTO_ID_NAME = $fotoNombreParaDispositivo;
                        $userInfo->PHOTO_ID_SIZE = $fotoSize;
                        $userInfo->PHOTO_ID_CONTENT = $fotoBase64;

                        // Valores predeterminados
                        $userInfo->PASSWORD = "";
                        $userInfo->FACE_GROUP_ID = 0;
                        $userInfo->ACC_GROUP_ID = 0;
                        $userInfo->DEPT_ID = 0;
                        $userInfo->IS_GROUP_TZ = 0;
                        $userInfo->VERIFY_TYPE = 0;
                        $userInfo->category = 0;
                        $userInfo->PRIVILEGE = 0;

                        $userInfo->save();

                        // Añadir a la cola de procesamiento por lotes
                        $loteUserInfos[] = $userInfo;

                        // Procesar en lotes para mejorar rendimiento
                        if (count($loteUserInfos) >= $loteSize || $index == count($tripulantes) - 1) {
                            // Procesar lote actual de usuarios
                            foreach ($loteUserInfos as $info) {
                                ManagerFactory::getCommandManager()->createUpdateUserInfosCommandByIds(
                                    $info,
                                    $devFuns
                                );
                            }

                            // Reiniciar el lote
                            $loteUserInfos = [];
                        }

                        $dispositivoResult['usuarios_sincronizados']++;

                    } catch (\Exception $e) {
                        // Registrar el error pero continuar con el siguiente tripulante
                        $dispositivoResult['usuarios_fallidos']++;

                        // Añadir detalles del error para diagnóstico
                        $dispositivoResult['detalles'][] = [
                            'tripulante_id' => $tripulante->id_tripulante,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString() // Opcional: para diagnóstico detallado
                        ];

                        // También registrar en log
                        Log::error("Error sincronizando tripulante ID: {$tripulante->id_tripulante} - Error: {$e->getMessage()}");
                    }
                }

                // Ejecutar comandos para este dispositivo una sola vez al final
                $this->executeDeviceCommands($dispositivo->DEVICE_SN);

                $resultadosSincronizacion[] = $dispositivoResult;
            }

            return response()->json([
                'message' => 'Proceso de sincronización completado para los dispositivos seleccionados.',
                'results' => $resultadosSincronizacion
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Ha ocurrido un error durante la sincronización.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Ejecutar los comandos pendientes en el dispositivo por su número de serie.
     *
     * @param string $deviceSn
     */
    private function executeDeviceCommands(string $deviceSn)
    {
        try {
            $commandManager = ManagerFactory::getCommandManager();
            $commands = $commandManager->getDeviceCommandListToDevice($deviceSn);

            if ($commands->isEmpty()) {

                return;
            }

            foreach ($commands as $command) {

                $command->CMD_TRANS_TIMES = now();
                $commandManager->updateDeviceCommand([$command]);

                // $result = $this->simulateCommandExecution($command);

                $command->CMD_RETURN = $result['status'];
                $command->CMD_RETURN_INFO = $result['info'];
                $command->CMD_OVER_TIME = now();
                $commandManager->updateDeviceCommand([$command]);


            }

            ManagerFactory::getDeviceManager()->updateDeviceState($deviceSn, 'Online', now());
            ManagerFactory::getCommandManager()->createINFOCommand($deviceSn);
        } catch (\Exception $e) {
            Log::error("Error ejecutando comandos para dispositivo SN: {$deviceSn}. Error: {$e->getMessage()}");
        }
    }

    /**
     * Simular la ejecución de un comando en un dispositivo.
     *
     * @param object $command
     * @return array
     */
    private function simulateCommandExecution($command)
    {
        return [
            'status' => 'OK',
            'info' => "Simulación de ejecución para comando ID: {$command->DEV_CMD_ID}"
        ];
    }

    /**
     * Comprime una imagen antes de convertirla a base64.
     *
     * @param string $imageData Los datos binarios de la imagen
     * @param int $quality La calidad de compresión (0-100)
     * @return string Los datos binarios de la imagen comprimida
     */
    private function compressImage($imageData, $quality = 75)
    {
        // Crear una imagen desde los datos
        $image = imagecreatefromstring($imageData);

        if ($image === false) {
            // Si no se puede crear la imagen, devolver los datos originales
            return $imageData;
        }

        // Crear un buffer de salida para guardar la imagen comprimida
        ob_start();

        // Comprimir y guardar la imagen en formato JPEG con la calidad especificada
        imagejpeg($image, null, $quality);

        // Obtener los datos comprimidos del buffer
        $compressedData = ob_get_contents();

        // Limpiar el buffer
        ob_end_clean();

        // Liberar memoria
        imagedestroy($image);

        return $compressedData;
    }

    /**
     * Limpiar los datos de los dispositivos seleccionados.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function clearDevices(Request $request)
    {
        try {
            // Validar los dispositivos seleccionados con el nombre correcto de la columna
            $validator = Validator::make($request->all(), [
                'device_ids' => 'required|array',
                'device_ids.*' => 'required|integer|exists:profacex_device_info,DEVICE_ID',
            ]);

            if ($validator->fails()) {
                return response()->json(['error' => $validator->errors()], 422);
            }

            $deviceIds = $request->device_ids;
            $resultadosLimpieza = [];

            // Usar DEVICE_ID en lugar de ID
            $dispositivosInfo = ProFxDeviceInfo::whereIn('DEVICE_ID', $deviceIds)->get();

            if ($dispositivosInfo->isEmpty()) {
                return response()->json(['error' => 'No se encontraron dispositivos válidos para limpiar.'], 400);
            }

            foreach ($dispositivosInfo as $dispositivo) {
                $deviceSn = $dispositivo->DEVICE_SN;
                $tablasBorradas = 0;
                $tablasFallidas = 0;

                // Mapeo de modelos a nombres de tabla explícitos
                // Este mapeo sobrescribe lo que devuelve getTable() si es necesario
                $modelosTablas = [
                    'ProFxAdvInfo' => null, // null significa usar getTable() del modelo
                    'ProFxAttLog' => null,
                    'ProFxAttPhoto' => null,
                    'ProFxDeviceAttrs' => null,
                    'ProFxMeetInfo' => 'proface_x_meet_info', // Forzar este nombre específico
                    'ProFxMessage' => null,
                    'ProFxUserInfo' => null,
                    'ProFxPersBioTemplate' => null,
                ];

                $errores = [];

                // Optimizacion: Ejecutar en transacción para mejorar rendimiento
                DB::beginTransaction();

                try {
                    // Procesar cada modelo con manejo de errores individual
                    foreach ($modelosTablas as $nombreModelo => $nombreTablaExplicito) {
                        try {
                            $nombreClaseCompleto = 'App\\Models\\ZKTeco\\ProFaceX\\' . $nombreModelo;

                            if (!class_exists($nombreClaseCompleto)) {
                                $errores[$nombreModelo] = "Clase {$nombreClaseCompleto} no existe";
                                $tablasFallidas++;
                                continue;
                            }

                            $instanciaModelo = new $nombreClaseCompleto();

                            // Usar nombre explícito si se proporcionó, o el del modelo
                            $nombreTabla = $nombreTablaExplicito ?: $instanciaModelo->getTable();

                            // Usar Query Builder para más control
                            $numFilasBorradas = DB::table($nombreTabla)
                                ->where('DEVICE_SN', '=', $deviceSn)
                                ->delete();

                            $tablasBorradas++;

                        } catch (\Exception $e) {
                            $errores[$nombreModelo] = $e->getMessage();
                            $tablasFallidas++;
                        }
                    }

                    DB::commit();
                } catch (\Exception $e) {
                    DB::rollback();
                    throw $e;
                }

                // Crear comando para limpiar todos los datos en el dispositivo físico
                ManagerFactory::getCommandManager()->createClearAllDataCommand($deviceSn);

                // Ejecutar comandos para este dispositivo
                $this->executeDeviceCommands($deviceSn);

                $resultadosLimpieza[] = [
                    'device_id' => $dispositivo->DEVICE_ID,
                    'device_sn' => $deviceSn,
                    'status' => 'clear_commands_executed',
                    'tablas_borradas' => $tablasBorradas,
                    'tablas_con_error' => $tablasFallidas
                ];
            }

            return response()->json([
                'message' => 'Datos locales eliminados y comandos de limpieza ejecutados en los dispositivos.',
                'results' => $resultadosLimpieza
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Ha ocurrido un error al limpiar los dispositivos.',
                'details' => $e->getMessage()
            ], 500);
        }
    }
}
