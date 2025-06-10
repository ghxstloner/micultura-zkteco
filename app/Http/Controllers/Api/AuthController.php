<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TripulanteSolicitud;
use App\Models\Posicion;
use App\Models\Aerolinea;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AuthController extends Controller
{
    /**
     * Registro de nuevo tripulante.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function register(Request $request): JsonResponse
    {
        try {
            // Validar los datos de entrada
            $validator = Validator::make($request->all(), [
                'crew_id' => 'required|string|max:10|unique:tripulantes_solicitudes,crew_id',
                'nombres' => 'required|string|max:50',
                'apellidos' => 'required|string|max:50',
                'pasaporte' => 'required|string|max:20|unique:tripulantes_solicitudes,pasaporte',
                'identidad' => 'nullable|string|max:20',
                'iata_aerolinea' => 'required|string|max:10|exists:aerolineas,siglas', // ← NUEVO CAMPO
                'posicion' => 'required|integer|exists:posiciones,id_posicion',
                'password' => 'required|string|min:6',
                'image' => 'nullable|image|mimes:jpeg,jpg,png,gif|max:5120', // Max 5MB
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Datos de entrada inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Procesar imagen si existe
            $nombreImagen = null;
            if ($request->hasFile('image')) {
                try {
                    $archivo = $request->file('image');

                    if (!$archivo->isValid()) {
                        throw new \Exception('Archivo de imagen inválido');
                    }

                    // Generar nombre del archivo
                    $extension = $archivo->getClientOriginalExtension();
                    $nombreArchivo = 'foto.' . $extension;

                    // Crear directorio en formato: {crew_id}/
                    $directorio = $request->crew_id;
                    $rutaCompleta = $directorio . '/' . $nombreArchivo;

                    // Guardar vía FTP al servidor
                    $disk = Storage::disk('crew_images');
                    $disk->makeDirectory($directorio);

                    $contenidoArchivo = file_get_contents($archivo->getPathname());
                    $guardado = $disk->put($rutaCompleta, $contenidoArchivo);

                    if (!$guardado) {
                        throw new \Exception('Error al guardar imagen en servidor remoto');
                    }

                    $nombreImagen = $nombreArchivo;
                    \Log::info("Imagen guardada exitosamente: {$rutaCompleta}");

                } catch (\Exception $e) {
                    \Log::error('Error al procesar imagen: ' . $e->getMessage());
                    return response()->json([
                        'success' => false,
                        'message' => 'Error al procesar la imagen: ' . $e->getMessage()
                    ], 500);
                }
            }

            // Crear la solicitud de tripulante
            DB::beginTransaction();

            $solicitud = TripulanteSolicitud::create([
                'crew_id' => $request->crew_id,
                'nombres' => $request->nombres,
                'apellidos' => $request->apellidos,
                'pasaporte' => $request->pasaporte,
                'identidad' => $request->identidad,
                'iata_aerolinea' => $request->iata_aerolinea, // ← NUEVO CAMPO
                'posicion' => $request->posicion,
                'imagen' => $nombreImagen,
                'password' => Hash::make($request->password),
                'estado' => TripulanteSolicitud::ESTADO_PENDIENTE,
                'activo' => true,
                'fecha_solicitud' => now(),
            ]);

            DB::commit();

            // Cargar relaciones
            $solicitud->load('posicionModel');

            return response()->json([
                'success' => true,
                'message' => 'Solicitud de registro enviada exitosamente. Espera la aprobación del administrador.',
                'data' => [
                    'id_solicitud' => $solicitud->id_solicitud,
                    'crew_id' => $solicitud->crew_id,
                    'nombres_apellidos' => $solicitud->nombres_apellidos,
                    'estado' => $solicitud->estado,
                    'fecha_solicitud' => $solicitud->fecha_solicitud->format('Y-m-d H:i:s'),
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error al registrar tripulante: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al procesar la solicitud de registro',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Inicio de sesión del tripulante.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function login(Request $request): JsonResponse
    {
        try {
            // Validar los datos de entrada
            $validator = Validator::make($request->all(), [
                'crew_id' => 'required|string|max:10',
                'password' => 'required|string|min:1',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Datos de entrada inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Buscar el tripulante por crew_id
            $tripulante = TripulanteSolicitud::with('posicionModel')
                ->where('crew_id', $request->crew_id)
                ->first();

            // Verificar si el tripulante existe
            if (!$tripulante) {
                return response()->json([
                    'success' => false,
                    'message' => 'Credenciales incorrectas'
                ], 401);
            }

            // Verificar si está aprobado
            if (!$tripulante->isApproved()) {
                $mensaje = match($tripulante->estado) {
                    TripulanteSolicitud::ESTADO_PENDIENTE => 'Tu solicitud está pendiente de aprobación. Por favor espera.',
                    TripulanteSolicitud::ESTADO_DENEGADO => 'Tu solicitud ha sido denegada. Contacta al administrador.',
                    default => 'No puedes acceder al sistema en este momento.'
                };

                return response()->json([
                    'success' => false,
                    'message' => $mensaje,
                    'estado' => $tripulante->estado,
                    'motivo_rechazo' => $tripulante->motivo_rechazo
                ], 401);
            }

            // Verificar si está activo
            if (!$tripulante->isActive()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tu cuenta está inactiva. Contacta al administrador.'
                ], 401);
            }

            // Verificar la contraseña
            if (!Hash::check($request->password, $tripulante->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Credenciales incorrectas'
                ], 401);
            }

            // Revocar tokens existentes (opcional)
            $tripulante->tokens()->delete();

            // Crear nuevo token
            $token = $tripulante->createToken('auth-token', ['*'], now()->addDays(30))->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Inicio de sesión exitoso',
                'data' => [
                    'tripulante' => [
                        'id_solicitud' => $tripulante->id_solicitud,
                        'crew_id' => $tripulante->crew_id,
                        'nombres' => $tripulante->nombres,
                        'apellidos' => $tripulante->apellidos,
                        'nombres_apellidos' => $tripulante->nombres_apellidos,
                        'pasaporte' => $tripulante->pasaporte,
                        'identidad' => $tripulante->identidad,
                        'iata_aerolinea' => $tripulante->iata_aerolinea, // ← INCLUIR EN RESPUESTA
                        'posicion' => [
                            'id_posicion' => $tripulante->posicionModel->id_posicion,
                            'codigo_posicion' => $tripulante->posicionModel->codigo_posicion,
                            'descripcion' => $tripulante->posicionModel->descripcion,
                        ],
                        'imagen_url' => $tripulante->imagen_url,
                        'activo' => $tripulante->activo,
                        'fecha_aprobacion' => $tripulante->fecha_aprobacion?->format('Y-m-d H:i:s'),
                    ],
                    'token' => $token,
                    'token_type' => 'Bearer',
                    'expires_at' => now()->addDays(30)->toDateTimeString()
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Error en login: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Cerrar sesión del tripulante.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            // Obtener el token actual
            $token = $request->user()->currentAccessToken();

            if ($token) {
                $token->delete();
            }

            return response()->json([
                'success' => true,
                'message' => 'Sesión cerrada exitosamente'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cerrar sesión',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Obtener información del tripulante autenticado.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function me(Request $request): JsonResponse
    {
        try {
            $tripulante = $request->user()->load('posicionModel');

            return response()->json([
                'success' => true,
                'message' => 'Información del tripulante obtenida exitosamente',
                'data' => [
                    'id_solicitud' => $tripulante->id_solicitud,
                    'crew_id' => $tripulante->crew_id,
                    'nombres' => $tripulante->nombres,
                    'apellidos' => $tripulante->apellidos,
                    'nombres_apellidos' => $tripulante->nombres_apellidos,
                    'pasaporte' => $tripulante->pasaporte,
                    'identidad' => $tripulante->identidad,
                    'iata_aerolinea' => $tripulante->iata_aerolinea, // ← INCLUIR
                    'posicion' => [
                        'id_posicion' => $tripulante->posicionModel->id_posicion,
                        'codigo_posicion' => $tripulante->posicionModel->codigo_posicion,
                        'descripcion' => $tripulante->posicionModel->descripcion,
                    ],
                    'imagen_url' => $tripulante->imagen_url,
                    'activo' => $tripulante->activo,
                    'estado' => $tripulante->estado,
                    'fecha_solicitud' => $tripulante->fecha_solicitud->format('Y-m-d H:i:s'),
                    'fecha_aprobacion' => $tripulante->fecha_aprobacion?->format('Y-m-d H:i:s'),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener información del tripulante',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Obtener todas las posiciones disponibles (PÚBLICO - NECESARIO PARA REGISTRO).
     *
     * @return JsonResponse
     */
    public function posiciones(): JsonResponse
    {
        try {
            $posiciones = Posicion::orderBy('descripcion')->get();

            return response()->json([
                'success' => true,
                'message' => 'Posiciones obtenidas exitosamente',
                'data' => $posiciones->map(function ($posicion) {
                    return [
                        'id_posicion' => $posicion->id_posicion,
                        'codigo_posicion' => $posicion->codigo_posicion,
                        'descripcion' => $posicion->descripcion,
                    ];
                })
            ]);

        } catch (\Exception $e) {
            \Log::error('Error al obtener posiciones: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener posiciones',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Obtener todas las aerolíneas disponibles (PÚBLICO - NECESARIO PARA REGISTRO).
     *
     * @return JsonResponse
     */
    public function aerolineas(): JsonResponse
    {
        try {
            $aerolineas = Aerolinea::orderBy('descripcion')->get();

            return response()->json([
                'success' => true,
                'message' => 'Aerolíneas obtenidas exitosamente',
                'data' => $aerolineas->map(function ($aerolinea) {
                    return [
                        'id_aerolinea' => $aerolinea->id_aerolinea,
                        'descripcion' => $aerolinea->descripcion,
                        'siglas' => $aerolinea->siglas,
                    ];
                })
            ]);

        } catch (\Exception $e) {
            \Log::error('Error al obtener aerolíneas: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener aerolíneas',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Verificar estado de solicitud (sin autenticación).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function checkStatus(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'crew_id' => 'required|string|max:10',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Crew ID es requerido',
                    'errors' => $validator->errors()
                ], 422);
            }

            $solicitud = TripulanteSolicitud::where('crew_id', $request->crew_id)->first();

            if (!$solicitud) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se encontró ninguna solicitud con este Crew ID'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Estado de solicitud obtenido',
                'data' => [
                    'crew_id' => $solicitud->crew_id,
                    'nombres_apellidos' => $solicitud->nombres_apellidos,
                    'estado' => $solicitud->estado,
                    'fecha_solicitud' => $solicitud->fecha_solicitud->format('Y-m-d H:i:s'),
                    'fecha_aprobacion' => $solicitud->fecha_aprobacion?->format('Y-m-d H:i:s'),
                    'motivo_rechazo' => $solicitud->motivo_rechazo,
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Error al verificar estado: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al verificar estado',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }
}