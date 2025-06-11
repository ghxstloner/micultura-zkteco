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
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Login de tripulante - SOLO USUARIOS APROBADOS Y ACTIVOS
     */
    public function login(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'crew_id' => 'required|string',
                'password' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Datos de entrada inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Buscar SOLO usuarios aprobados y activos
            $tripulante = TripulanteSolicitud::where('crew_id', $request->crew_id)
                ->where('estado', 'Aprobado')  // SOLO APROBADOS
                ->where('activo', true)        // SOLO ACTIVOS
                ->with('posicionModel')
                ->first();

            if (!$tripulante) {
                return response()->json([
                    'success' => false,
                    'message' => 'Credenciales inválidas o cuenta no autorizada'
                ], 401);
            }

            // Verificar contraseña
            if (!Hash::check($request->password, $tripulante->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Credenciales inválidas'
                ], 401);
            }

            // Revocar tokens anteriores para evitar problemas
            $tripulante->tokens()->delete();

            // Crear nuevo token
            $token = $tripulante->createToken('mobile-app')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Login exitoso',
                'data' => [
                    'token' => $token,
                    'tripulante' => [
                        'id_solicitud' => $tripulante->id_solicitud,
                        'crew_id' => $tripulante->crew_id,
                        'nombres' => $tripulante->nombres,
                        'apellidos' => $tripulante->apellidos,
                        'nombres_apellidos' => $tripulante->nombres_apellidos,
                        'pasaporte' => $tripulante->pasaporte,
                        'identidad' => $tripulante->identidad,
                        'iata_aerolinea' => $tripulante->iata_aerolinea,
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
     * Registro de nuevo tripulante
     */
    public function register(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'crew_id' => 'required|string|unique:tripulantes_solicitudes,crew_id',
                'nombres' => 'required|string|max:50',
                'apellidos' => 'required|string|max:50',
                'pasaporte' => 'required|string|max:20|unique:tripulantes_solicitudes,pasaporte',
                'identidad' => 'nullable|string|max:20',
                'iata_aerolinea' => 'required|string|max:2',
                'posicion' => 'required|integer|exists:posiciones,id_posicion',
                'password' => 'required|string|min:6',
                'image' => 'nullable|image|mimes:jpeg,jpg,png,gif|max:5120',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Datos de entrada inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            $nombreImagen = null;

            // Procesar imagen si existe
            if ($request->hasFile('image')) {
                try {
                    $archivo = $request->file('image');

                    if (!$archivo->isValid()) {
                        throw new \Exception('Archivo de imagen inválido');
                    }

                    $extension = $archivo->getClientOriginalExtension();
                    $nombreImagen = 'foto.' . $extension;
                    $directorio = $request->crew_id;
                    $rutaCompleta = $directorio . '/' . $nombreImagen;

                    $disk = Storage::disk('crew_images');
                    $disk->makeDirectory($directorio);

                    $contenidoArchivo = file_get_contents($archivo->getPathname());
                    $guardado = $disk->put($rutaCompleta, $contenidoArchivo);

                    if (!$guardado) {
                        throw new \Exception('Error al guardar imagen');
                    }

                } catch (\Exception $e) {
                    \Log::error('Error al procesar imagen: ' . $e->getMessage());
                    return response()->json([
                        'success' => false,
                        'message' => 'Error al procesar la imagen: ' . $e->getMessage()
                    ], 500);
                }
            }

            // Crear solicitud
            $solicitud = TripulanteSolicitud::create([
                'crew_id' => $request->crew_id,
                'nombres' => $request->nombres,
                'apellidos' => $request->apellidos,
                'pasaporte' => $request->pasaporte,
                'identidad' => $request->identidad,
                'iata_aerolinea' => $request->iata_aerolinea,
                'posicion' => $request->posicion,
                'imagen' => $nombreImagen,
                'password' => Hash::make($request->password),
                'estado' => 'Pendiente',
                'activo' => false,
                'fecha_solicitud' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Solicitud creada exitosamente',
                'data' => [
                    'id_solicitud' => $solicitud->id_solicitud,
                    'crew_id' => $solicitud->crew_id,
                    'nombres_apellidos' => $solicitud->nombres_apellidos,
                    'estado' => $solicitud->estado,
                    'fecha_solicitud' => $solicitud->fecha_solicitud->format('Y-m-d H:i:s'),
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Error en registro: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al procesar el registro',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Verificar estado de solicitud
     */
    public function checkStatus(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'crew_id' => 'required|string',
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
                    'message' => 'No se encontró ninguna solicitud con ese Crew ID'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Estado de solicitud obtenido exitosamente',
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

    /**
     * Obtener información del usuario autenticado
     */
    public function me(Request $request): JsonResponse
    {
        try {
            $tripulante = $request->user()->load('posicionModel');

            // Verificar que el usuario siga siendo válido
            if (!$tripulante->isApproved() || !$tripulante->activo) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autorizado'
                ], 401);
            }

            return response()->json([
                'success' => true,
                'message' => 'Información de usuario obtenida exitosamente',
                'data' => [
                    'id_solicitud' => $tripulante->id_solicitud,
                    'crew_id' => $tripulante->crew_id,
                    'nombres' => $tripulante->nombres,
                    'apellidos' => $tripulante->apellidos,
                    'nombres_apellidos' => $tripulante->nombres_apellidos,
                    'pasaporte' => $tripulante->pasaporte,
                    'identidad' => $tripulante->identidad,
                    'iata_aerolinea' => $tripulante->iata_aerolinea,
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
            \Log::error('Error al obtener información del usuario: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener información del usuario',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Logout
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            $request->user()->currentAccessToken()->delete();

            return response()->json([
                'success' => true,
                'message' => 'Logout exitoso'
            ]);

        } catch (\Exception $e) {
            \Log::error('Error en logout: ' . $e->getMessage());

            return response()->json([
                'success' => true,
                'message' => 'Logout completado'
            ]);
        }
    }

    /**
     * Obtener todas las posiciones disponibles
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
     * Obtener todas las aerolíneas disponibles
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
}