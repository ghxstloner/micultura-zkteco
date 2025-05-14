<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tripulante;
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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class TripulanteApiController extends Controller
{
    /**
     * Crear un nuevo tripulante y enviarlo a los dispositivos ZKTeco.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        // Validar los datos de entrada
        $validator = Validator::make($request->all(), [
            'id_aerolinea' => 'required|integer|exists:aerolineas,id_aerolinea',
            'crew_id' => 'required|string',
            'nombres' => 'required|string|max:100',
            'apellidos' => 'required|string|max:100',
            'pasaporte' => 'required|string|max:50',
            'identidad' => 'required|string|max:50',
            'posicion' => 'required|integer',
            'foto' => 'required|image|max:2048', // Foto como archivo
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        try {
            // Procesar y guardar la imagen si se proporcionó
            $fotoPath = null;
            if ($request->hasFile('foto')) {
                $foto = $request->file('foto');
                $nombreArchivo = time() . '_' . $request->identidad . '.' . $foto->getClientOriginalExtension();
                $fotoPath = 'tripulantes/' . $nombreArchivo;
                Storage::disk('local')->put($fotoPath, file_get_contents($foto));
            }

            // Crear el tripulante
            $tripulante = Tripulante::create([
                'id_aerolinea' => $request->id_aerolinea,
                'crew_id' => $request->crew_id,
                'nombres' => $request->nombres,
                'apellidos' => $request->apellidos,
                'pasaporte' => $request->pasaporte,
                'identidad' => $request->identidad,
                'posicion' => $request->posicion,
                'imagen' => $fotoPath,
            ]);

            // Enviar el tripulante a los dispositivos
            $this->enviarTripulanteADispositivos($tripulante);

            return response()->json([
                'message' => 'Tripulante creado y enviado a dispositivos exitosamente',
                'tripulante' => $tripulante
            ], 201);
        } catch (\Exception $e) {
            Log::error("Error al crear tripulante: {$e->getMessage()}");
            return response()->json([
                'error' => 'Error al crear el tripulante',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Sincronizar todos los tripulantes con los dispositivos seleccionados.
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
                'device_ids.*' => 'required|integer|exists:profx_device_info,ID',
            ]);

            if ($validator->fails()) {
                return response()->json(['error' => $validator->errors()], 422);
            }

            $deviceIds = $request->device_ids;

            // Si no hay dispositivos seleccionados, devolver un error
            if (empty($deviceIds)) {
                return response()->json([
                    'error' => 'No se seleccionaron dispositivos para sincronizar.'
                ], 400);
            }

            // Obtener todos los tripulantes
            $tripulantes = Tripulante::whereNotNull('imagen')->where('imagen', '!=', '')->get();

            // Guardar los dispositivos que se sincronizarán
            $dispositivos = [];

            // Iterar sobre los dispositivos seleccionados
            foreach ($deviceIds as $id) {
                // Buscar el dispositivo por ID
                if ($dispositivo = ProFxDeviceInfo::find($id)) {
                    $dispositivos[] = $dispositivo;
                }
            }

            // Si no se encontraron dispositivos válidos
            if (empty($dispositivos)) {
                return response()->json([
                    'error' => 'No se encontraron dispositivos válidos para sincronizar.'
                ], 400);
            }

            // Sincronizar los tripulantes con los dispositivos seleccionados
            foreach ($tripulantes as $tripulante) {
                // Verificar si el tripulante tiene foto
                $tieneFoto = $tripulante->imagen && Storage::disk('local')->exists($tripulante->imagen);
                if ($tieneFoto) {
                    $foto = Storage::disk('local')->get($tripulante->imagen);
                    $fotoSize = Storage::disk('local')->size($tripulante->imagen);
                    $fotoBase64 = base64_encode($foto);
                }

                // Para cada dispositivo seleccionado
                foreach ($dispositivos as $dispositivo) {
                    // Buscar o crear el usuario en el dispositivo
                    $userInfo = ProFxUserInfo::firstOrNew([
                        'USER_PIN' => $tripulante->id_tripulante,
                        'DEVICE_SN' => $dispositivo->DEVICE_SN
                    ]);

                    // Rellenar la información del usuario
                    $userInfo->fill([
                        'USER_PIN' => $tripulante->id_tripulante,
                        'NAME' => $tripulante->nombresApellidos,
                        'DEVICE_SN' => $dispositivo->DEVICE_SN,
                        'MAIN_CARD' => $tripulante->id_tripulante,
                        'PHOTO_ID_NAME' => $tieneFoto ? "{$tripulante->identidad}.jpg" : null,
                        'PHOTO_ID_SIZE' => $tieneFoto ? $fotoSize : null,
                        'PHOTO_ID_CONTENT' => $tieneFoto ? $fotoBase64 : null,
                        'PASSWORD' => '',
                        'FACE_GROUP_ID' => 0,
                        'ACC_GROUP_ID' => 0,
                        'DEPT_ID' => 0,
                        'IS_GROUP_TZ' => 0,
                        'VERIFY_TYPE' => 0,
                        'category' => 0,
                        'PRIVILEGE' => 0
                    ]);

                    // Guardar la información del usuario
                    $userInfo->save();

                    // Generar comando para actualizar el usuario en el dispositivo
                    ManagerFactory::getCommandManager()->createUpdateUserInfosCommandByIds($userInfo, $dispositivo->DEV_FUNS ?? '');
                }
            }

            // Ejecutar los comandos pendientes en los dispositivos
            $results = [];
            foreach ($dispositivos as $dispositivo) {
                $this->executeDeviceCommands($dispositivo->DEVICE_SN);
                $results[] = [
                    'device_id' => $dispositivo->ID,
                    'device_sn' => $dispositivo->DEVICE_SN,
                    'status' => 'success'
                ];
            }

            // Responder con éxito
            return response()->json([
                'message' => 'Sincronización completada exitosamente',
                'results' => $results
            ]);

        } catch (\Exception $e) {
            Log::error("Error en la sincronización de tripulantes: {$e->getMessage()}");
            return response()->json([
                'error' => 'Ha ocurrido un error durante la sincronización.',
                'details' => $e->getMessage()
            ], 500);
        }
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
            // Validar los dispositivos seleccionados
            $validator = Validator::make($request->all(), [
                'device_ids' => 'required|array',
                'device_ids.*' => 'required|integer|exists:profx_device_info,ID',
            ]);

            if ($validator->fails()) {
                return response()->json(['error' => $validator->errors()], 422);
            }

            $deviceIds = $request->device_ids;
            $dispositivos = [];

            foreach ($deviceIds as $id) {
                if ($entry = ProFxDeviceInfo::find($id)) {
                    $deviceSn = $entry->DEVICE_SN;
                    ProFxAdvInfo::where('DEVICE_SN', '=', $deviceSn)->delete();
                    ProFxAttLog::where('DEVICE_SN', '=', $deviceSn)->delete();
                    ProFxAttPhoto::where('DEVICE_SN', '=', $deviceSn)->delete();
                    ProFxDeviceAttrs::where('DEVICE_SN', '=', $deviceSn)->delete();
                    ProFxMeetInfo::where('DEVICE_SN', '=', $deviceSn)->delete();
                    ProFxMessage::where('DEVICE_SN', '=', $deviceSn)->delete();
                    ProFxUserInfo::where('DEVICE_SN', '=', $deviceSn)->delete();
                    ProFxPersBioTemplate::where('DEVICE_SN', '=', $deviceSn)->delete();
                    $dispositivos[] = [
                        'device_id' => $entry->ID,
                        'device_sn' => $deviceSn,
                        'status' => 'cleared'
                    ];
                    ManagerFactory::getCommandManager()->createClearAllDataCommand($deviceSn);
                }
            }

            return response()->json([
                'message' => 'Dispositivos limpiados exitosamente',
                'results' => $dispositivos
            ]);
        } catch (\Exception $e) {
            Log::error("Error al limpiar dispositivos: {$e->getMessage()}");
            return response()->json([
                'error' => 'Ha ocurrido un error al limpiar los dispositivos.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Enviar un tripulante a todos los dispositivos activos.
     *
     * @param Tripulante $tripulante
     * @return bool
     */
    private function enviarTripulanteADispositivos(Tripulante $tripulante)
    {
        // Verificar si el tripulante tiene foto
        $tieneFoto = $tripulante->imagen && Storage::disk('local')->exists($tripulante->imagen);
        if ($tieneFoto) {
            $foto = Storage::disk('local')->get($tripulante->imagen);
            $fotoSize = Storage::disk('local')->size($tripulante->imagen);
            $fotoBase64 = base64_encode($foto);
        }

        // Para cada dispositivo activo
        foreach (ProFxDeviceInfo::where('state', 'Online')->get() as $dispositivo) {
            // Buscar o crear el usuario en el dispositivo
            $userInfo = ProFxUserInfo::where(['USER_PIN' => $tripulante->id_tripulante, 'DEVICE_SN' => $dispositivo->DEVICE_SN])->first();
            if (!$userInfo) {
                $userInfo = new ProFxUserInfo;
            }

            // Rellenar la información del usuario
            $userInfo->USER_PIN = $tripulante->id_tripulante;
            $userInfo->NAME = $tripulante->nombresApellidos;
            $userInfo->DEVICE_SN = $dispositivo->DEVICE_SN;
            $userInfo->MAIN_CARD = $tripulante->id_tripulante;

            if ($tieneFoto) {
                $userInfo->PHOTO_ID_NAME = "{$tripulante->identidad}.jpg";
                $userInfo->PHOTO_ID_SIZE = $fotoSize;
                $userInfo->PHOTO_ID_CONTENT = $fotoBase64;
            } else {
                $userInfo->PHOTO_ID_NAME = null;
                $userInfo->PHOTO_ID_SIZE = null;
                $userInfo->PHOTO_ID_CONTENT = null;
            }

            $userInfo->PASSWORD = "";
            $userInfo->FACE_GROUP_ID = 0;
            $userInfo->ACC_GROUP_ID = 0;
            $userInfo->DEPT_ID = 0;
            $userInfo->IS_GROUP_TZ = 0;
            $userInfo->VERIFY_TYPE = 0;
            $userInfo->category = 0; // Ordinario
            $userInfo->PRIVILEGE = 0; // Usuario ordinario
            $userInfo->save();

            // Generar comando para actualizar el usuario en el dispositivo
            ManagerFactory::getCommandManager()->createUpdateUserInfosCommandByIds($userInfo, $dispositivo->DEV_FUNS ?? '');
        }

        return true;
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
                Log::info("No hay comandos pendientes para el dispositivo SN: {$deviceSn}");
                return;
            }

            foreach ($commands as $command) {
                Log::info("Ejecutando comando ID: {$command->DEV_CMD_ID} en dispositivo SN: {$deviceSn}");
                $command->CMD_TRANS_TIMES = now();
                $commandManager->updateDeviceCommand([$command]);

                $result = $this->simulateCommandExecution($command);

                $command->CMD_RETURN = $result['status'];
                $command->CMD_RETURN_INFO = $result['info'];
                $command->CMD_OVER_TIME = now();
                $commandManager->updateDeviceCommand([$command]);

                Log::info("Comando ejecutado: {$command->DEV_CMD_ID} - Resultado: {$result['status']}");
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
}