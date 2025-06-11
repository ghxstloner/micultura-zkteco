<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Planificacion;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class TripulanteApiController extends Controller
{
    /**
     * Obtener planificaciones del tripulante autenticado
     */
    public function getPlanificaciones(Request $request): JsonResponse
    {
        try {
            $tripulante = $request->user();
            $page = $request->get('page', 1);
            $perPage = $request->get('per_page', 15);
            $search = $request->get('search', '');

            // Query con datos reales de tu tabla
            $query = Planificacion::with(['aerolinea', 'posicionModel'])
                ->where('crew_id', $tripulante->crew_id)
                ->orderBy('fecha_vuelo', 'desc')
                ->orderBy('hora_vuelo', 'desc');

            // Filtro de búsqueda solo en campos que existen
            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('numero_vuelo', 'like', "%{$search}%")
                      ->orWhere('crew_id', 'like', "%{$search}%")
                      ->orWhere('iata_aerolinea', 'like', "%{$search}%");
                });
            }

            $planificaciones = $query->paginate($perPage, ['*'], 'page', $page);

            // Mapear SOLO los datos que realmente existen
            $transformedData = $planificaciones->getCollection()->map(function ($planificacion) {
                return [
                    'id_planificacion' => $planificacion->id,
                    'crew_id' => $planificacion->crew_id,
                    'fecha_vuelo' => $planificacion->fecha_vuelo ? $planificacion->fecha_vuelo->format('Y-m-d') : null,
                    'numero_vuelo' => $planificacion->numero_vuelo,
                    'hora_salida' => $planificacion->hora_vuelo, // Tu campo real
                    'estado' => $this->mapearEstatus($planificacion->estatus),
                    'iata_aerolinea' => $planificacion->iata_aerolinea,
                    'posicion' => $planificacion->posicionModel ? $planificacion->posicionModel->codigo_posicion : 'N/A',

                    // Campos que NO existen en tu tabla - devolver null
                    'origen' => null,
                    'destino' => null,
                    'hora_llegada' => null,
                    'aeronave' => null,
                    'observaciones' => null,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Planificaciones obtenidas exitosamente',
                'data' => $transformedData,
                'pagination' => [
                    'current_page' => $planificaciones->currentPage(),
                    'total' => $planificaciones->total(),
                    'per_page' => $planificaciones->perPage(),
                    'last_page' => $planificaciones->lastPage(),
                    'from' => $planificaciones->firstItem(),
                    'to' => $planificaciones->lastItem(),
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Error al obtener planificaciones: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener planificaciones',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Mapear estatus real: P = Pendiente, R = Procesada
     */
    private function mapearEstatus($estatus)
    {
        switch ($estatus) {
            case 'P':
                return 'Pendiente';
            case 'R':
                return 'Procesada';
            default:
                return 'Desconocido';
        }
    }

    /**
     * Obtener una planificación específica
     */
    public function getPlanificacion(Request $request, int $id): JsonResponse
    {
        try {
            $tripulante = $request->user();

            $planificacion = Planificacion::with(['aerolinea', 'posicionModel'])
                ->where('id', $id)
                ->where('crew_id', $tripulante->crew_id)
                ->first();

            if (!$planificacion) {
                return response()->json([
                    'success' => false,
                    'message' => 'Planificación no encontrada'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Planificación obtenida exitosamente',
                'data' => [
                    'id_planificacion' => $planificacion->id,
                    'crew_id' => $planificacion->crew_id,
                    'fecha_vuelo' => $planificacion->fecha_vuelo ? $planificacion->fecha_vuelo->format('Y-m-d') : null,
                    'numero_vuelo' => $planificacion->numero_vuelo,
                    'hora_salida' => $planificacion->hora_vuelo,
                    'estado' => $this->mapearEstatus($planificacion->estatus),
                    'iata_aerolinea' => $planificacion->iata_aerolinea,
                    'posicion' => $planificacion->posicionModel ? $planificacion->posicionModel->codigo_posicion : 'N/A',

                    // Campos que NO existen
                    'origen' => null,
                    'destino' => null,
                    'hora_llegada' => null,
                    'aeronave' => null,
                    'observaciones' => null,
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Error al obtener planificación: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener planificación',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Obtener perfil del tripulante
     */
    public function getProfile(Request $request): JsonResponse
    {
        try {
            $tripulante = $request->user()->load('posicionModel');

            return response()->json([
                'success' => true,
                'message' => 'Perfil obtenido exitosamente',
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
            \Log::error('Error al obtener perfil: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener perfil',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Actualizar perfil del tripulante
     */
    public function updateProfile(Request $request): JsonResponse
    {
        try {
            $tripulante = $request->user();

            $validator = Validator::make($request->all(), [
                'nombres' => 'sometimes|required|string|max:50',
                'apellidos' => 'sometimes|required|string|max:50',
                'pasaporte' => 'sometimes|required|string|max:20|unique:tripulantes_solicitudes,pasaporte,' . $tripulante->id_solicitud . ',id_solicitud',
                'identidad' => 'nullable|string|max:20',
                'image' => 'nullable|image|mimes:jpeg,jpg,png,gif|max:5120',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Datos de entrada inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Procesar imagen si existe
            if ($request->hasFile('image')) {
                try {
                    $archivo = $request->file('image');

                    if (!$archivo->isValid()) {
                        throw new \Exception('Archivo de imagen inválido');
                    }

                    $extension = $archivo->getClientOriginalExtension();
                    $nombreArchivo = 'foto.' . $extension;
                    $directorio = $tripulante->crew_id;
                    $rutaCompleta = $directorio . '/' . $nombreArchivo;

                    $disk = Storage::disk('crew_images');
                    $disk->makeDirectory($directorio);

                    $contenidoArchivo = file_get_contents($archivo->getPathname());
                    $guardado = $disk->put($rutaCompleta, $contenidoArchivo);

                    if (!$guardado) {
                        throw new \Exception('Error al guardar imagen');
                    }

                    $tripulante->imagen = $nombreArchivo;

                } catch (\Exception $e) {
                    \Log::error('Error al procesar imagen: ' . $e->getMessage());
                    return response()->json([
                        'success' => false,
                        'message' => 'Error al procesar la imagen: ' . $e->getMessage()
                    ], 500);
                }
            }

            // Actualizar campos
            if ($request->has('nombres')) {
                $tripulante->nombres = $request->nombres;
            }
            if ($request->has('apellidos')) {
                $tripulante->apellidos = $request->apellidos;
            }
            if ($request->has('pasaporte')) {
                $tripulante->pasaporte = $request->pasaporte;
            }
            if ($request->has('identidad')) {
                $tripulante->identidad = $request->identidad;
            }

            $tripulante->save();
            $tripulante->load('posicionModel');

            return response()->json([
                'success' => true,
                'message' => 'Perfil actualizado exitosamente',
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
            \Log::error('Error al actualizar perfil: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar perfil',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Cambiar contraseña del tripulante
     */
    public function changePassword(Request $request): JsonResponse
    {
        try {
            $tripulante = $request->user();

            $validator = Validator::make($request->all(), [
                'current_password' => 'required|string',
                'new_password' => 'required|string|min:6',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Datos de entrada inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            if (!Hash::check($request->current_password, $tripulante->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'La contraseña actual es incorrecta'
                ], 400);
            }

            $tripulante->password = Hash::make($request->new_password);
            $tripulante->save();

            $tripulante->tokens()->delete();

            return response()->json([
                'success' => true,
                'message' => 'Contraseña actualizada exitosamente. Por favor inicia sesión nuevamente.',
            ]);

        } catch (\Exception $e) {
            \Log::error('Error al cambiar contraseña: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al cambiar contraseña',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    // ===== MÉTODOS PARA ADMINISTRACIÓN (CRUD) =====

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Funcionalidad no implementada'
        ], 501);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Funcionalidad no implementada'
        ], 501);
    }

    public function show(Request $request, $id): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Funcionalidad no implementada'
        ], 501);
    }

    public function update(Request $request, $id): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Funcionalidad no implementada'
        ], 501);
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Funcionalidad no implementada'
        ], 501);
    }
}