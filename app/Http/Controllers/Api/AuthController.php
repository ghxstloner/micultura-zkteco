<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TripulanteSolicitud;
use App\Models\Posicion;
use App\Models\Aerolinea;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class AuthController extends Controller
{
    /**
     * Login del tripulante
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

            // Buscar solicitud aprobada
            $solicitud = TripulanteSolicitud::where('crew_id', $request->crew_id)
                ->where('estado', 'Aprobado')
                ->where('activo', true)
                ->with('posicionModel')
                ->first();

            if (!$solicitud) {
                return response()->json([
                    'success' => false,
                    'message' => 'Credenciales inválidas o cuenta no activa'
                ], 401);
            }

            // Verificar contraseña
            if (!Hash::check($request->password, $solicitud->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Credenciales inválidas'
                ], 401);
            }

            // Generar token
            $token = $solicitud->createToken('CrewManager')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Login exitoso',
                'data' => [
                    'token' => $token,
                    'tripulante' => [
                        'id_solicitud' => $solicitud->id_solicitud,
                        'crew_id' => $solicitud->crew_id,
                        'nombres' => $solicitud->nombres,
                        'apellidos' => $solicitud->apellidos,
                        'nombres_apellidos' => $solicitud->nombres_apellidos,
                        'pasaporte' => $solicitud->pasaporte,
                        'identidad' => $solicitud->identidad,
                        'iata_aerolinea' => $solicitud->iata_aerolinea,
                        'posicion' => [
                            'id_posicion' => $solicitud->posicionModel->id_posicion,
                            'codigo_posicion' => $solicitud->posicionModel->codigo_posicion,
                            'descripcion' => $solicitud->posicionModel->descripcion,
                        ],
                        'imagen_url' => $solicitud->imagen_url,
                        'activo' => $solicitud->activo,
                        'estado' => $solicitud->estado,
                        'fecha_solicitud' => $solicitud->fecha_solicitud->format('Y-m-d H:i:s'),
                        'fecha_aprobacion' => $solicitud->fecha_aprobacion?->format('Y-m-d H:i:s'),
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error en el login',
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
                'crew_id' => 'required|string|max:10|unique:tripulantes_solicitudes,crew_id',
                'nombres' => 'required|string|max:50',
                'apellidos' => 'required|string|max:50',
                'pasaporte' => 'required|string|max:20|unique:tripulantes_solicitudes,pasaporte',
                'identidad' => 'nullable|string|max:20',
                'iata_aerolinea' => 'required|string|max:10',
                'posicion' => 'required|integer|exists:posiciones,id_posicion',
                'password' => 'required|string|min:6',
                'image' => 'required|image|mimes:jpeg,jpg,png,gif|max:5120',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Datos de entrada inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Procesar imagen
            $nombreImagen = null;
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

                    // Guardar imagen
                    $disk = Storage::disk('crew_images');
                    $disk->makeDirectory($directorio);

                    $contenidoArchivo = file_get_contents($archivo->getPathname());
                    $guardado = $disk->put($rutaCompleta, $contenidoArchivo);

                    if (!$guardado) {
                        throw new \Exception('Error al guardar imagen');
                    }

                } catch (\Exception $e) {
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
                'message' => 'Solicitud de registro enviada exitosamente',
                'data' => [
                    'id_solicitud' => $solicitud->id_solicitud,
                    'crew_id' => $solicitud->crew_id,
                    'nombres_apellidos' => $solicitud->nombres_apellidos,
                    'estado' => $solicitud->estado,
                    'fecha_solicitud' => $solicitud->fecha_solicitud->format('Y-m-d H:i:s'),
                ]
            ]);

        } catch (\Exception $e) {
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
                    'message' => 'No se encontró solicitud para este Crew ID'
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
            return response()->json([
                'success' => false,
                'message' => 'Error al verificar estado',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Información del usuario autenticado
     */
    public function me(Request $request): JsonResponse
    {
        try {
            $solicitud = $request->user()->load('posicionModel');

            return response()->json([
                'success' => true,
                'message' => 'Información del usuario obtenida',
                'data' => [
                    'id_solicitud' => $solicitud->id_solicitud,
                    'crew_id' => $solicitud->crew_id,
                    'nombres' => $solicitud->nombres,
                    'apellidos' => $solicitud->apellidos,
                    'nombres_apellidos' => $solicitud->nombres_apellidos,
                    'pasaporte' => $solicitud->pasaporte,
                    'identidad' => $solicitud->identidad,
                    'iata_aerolinea' => $solicitud->iata_aerolinea,
                    'posicion' => [
                        'id_posicion' => $solicitud->posicionModel->id_posicion,
                        'codigo_posicion' => $solicitud->posicionModel->codigo_posicion,
                        'descripcion' => $solicitud->posicionModel->descripcion,
                    ],
                    'imagen_url' => $solicitud->imagen_url,
                    'activo' => $solicitud->activo,
                    'estado' => $solicitud->estado,
                    'fecha_solicitud' => $solicitud->fecha_solicitud->format('Y-m-d H:i:s'),
                    'fecha_aprobacion' => $solicitud->fecha_aprobacion?->format('Y-m-d H:i:s'),
                ]
            ]);

        } catch (\Exception $e) {
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
            return response()->json([
                'success' => false,
                'message' => 'Error en logout',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Obtener posiciones disponibles
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
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener posiciones',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Obtener aerolíneas disponibles
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
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener aerolíneas',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }
}