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
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getPlanificaciones(Request $request): JsonResponse
    {
        try {
            $tripulante = $request->user();
            $page = $request->get('page', 1);
            $perPage = $request->get('per_page', 15);
            $search = $request->get('search', '');

            // Construir query
            $query = Planificacion::where('crew_id', $tripulante->crew_id)
                ->orderBy('fecha_vuelo', 'desc')
                ->orderBy('hora_salida', 'desc');

            // Aplicar filtro de búsqueda si existe
            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('numero_vuelo', 'like', "%{$search}%")
                      ->orWhere('origen', 'like', "%{$search}%")
                      ->orWhere('destino', 'like', "%{$search}%")
                      ->orWhere('aeronave', 'like', "%{$search}%")
                      ->orWhere('estado', 'like', "%{$search}%");
                });
            }

            // Obtener resultados paginados
            $planificaciones = $query->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'success' => true,
                'message' => 'Planificaciones obtenidas exitosamente',
                'data' => $planificaciones->items(),
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
     * Obtener una planificación específica
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function getPlanificacion(Request $request, int $id): JsonResponse
    {
        try {
            $tripulante = $request->user();

            $planificacion = Planificacion::where('id_planificacion', $id)
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
                'data' => $planificacion
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
     *
     * @param Request $request
     * @return JsonResponse
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
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function updateProfile(Request $request): JsonResponse
    {
        try {
            $tripulante = $request->user();

            // Validar datos
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

                    // Guardar nueva imagen
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
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function changePassword(Request $request): JsonResponse
    {
        try {
            $tripulante = $request->user();

            // Validar datos
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

            // Verificar contraseña actual
            if (!Hash::check($request->current_password, $tripulante->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'La contraseña actual es incorrecta'
                ], 400);
            }

            // Actualizar contraseña
            $tripulante->password = Hash::make($request->new_password);
            $tripulante->save();

            // Revocar todos los tokens para forzar nuevo login
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

    /**
     * Listar todos los tripulantes (para administradores)
     */
    public function index(Request $request): JsonResponse
    {
        // TODO: Implementar listado para administradores
        return response()->json([
            'success' => false,
            'message' => 'Funcionalidad no implementada'
        ], 501);
    }

    /**
     * Crear nuevo tripulante (para administradores)
     */
    public function store(Request $request): JsonResponse
    {
        // TODO: Implementar creación para administradores
        return response()->json([
            'success' => false,
            'message' => 'Funcionalidad no implementada'
        ], 501);
    }

    /**
     * Mostrar tripulante específico (para administradores)
     */
    public function show(Request $request, $id): JsonResponse
    {
        // TODO: Implementar para administradores
        return response()->json([
            'success' => false,
            'message' => 'Funcionalidad no implementada'
        ], 501);
    }

    /**
     * Actualizar tripulante (para administradores)
     */
    public function update(Request $request, $id): JsonResponse
    {
        // TODO: Implementar para administradores
        return response()->json([
            'success' => false,
            'message' => 'Funcionalidad no implementada'
        ], 501);
    }

    /**
     * Eliminar tripulante (para administradores)
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        // TODO: Implementar para administradores
        return response()->json([
            'success' => false,
            'message' => 'Funcionalidad no implementada'
        ], 501);
    }
}