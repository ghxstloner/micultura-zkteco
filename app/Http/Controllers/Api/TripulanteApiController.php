<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TripulanteResource;
use App\Http\Resources\PosicionResource;
use App\Models\Tripulante;
use App\Models\Posicion;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class TripulanteApiController extends Controller
{
    /**
     * Listar tripulantes con filtros opcionales.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Obtener el usuario autenticado
            $user = $request->user();

            // Validar parámetros de consulta
            $validator = Validator::make($request->all(), [
                'page' => 'integer|min:1',
                'per_page' => 'integer|min:1|max:100',
                'search' => 'string|max:255',
                'posicion' => 'integer|exists:posiciones,id_posicion',
                'id_aerolinea' => 'integer|exists:aerolineas,id_aerolinea',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Parámetros de consulta inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Inicializar consulta
            $query = Tripulante::with(['aerolinea', 'posicionModel']);

            // Si el usuario no es admin, solo puede ver tripulantes de su aerolínea
            if (!$user->isAdmin() && $user->id_aerolinea) {
                $query->porAerolinea($user->id_aerolinea);
            }

            // Aplicar filtros
            if ($request->filled('search')) {
                $query->buscarPorNombre($request->search);
            }

            if ($request->filled('posicion')) {
                $query->porPosicion($request->posicion);
            }

            if ($request->filled('id_aerolinea')) {
                // Solo admin puede filtrar por aerolínea diferente a la suya
                if ($user->isAdmin() || $user->id_aerolinea == $request->id_aerolinea) {
                    $query->porAerolinea($request->id_aerolinea);
                }
            }

            // Ordenar por fecha de creación descendente
            $query->orderBy('fecha_creacion', 'desc');

            // Paginación
            $perPage = $request->get('per_page', 15);
            $tripulantes = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Tripulantes obtenidos exitosamente',
                'data' => TripulanteResource::collection($tripulantes),
                'pagination' => [
                    'current_page' => $tripulantes->currentPage(),
                    'last_page' => $tripulantes->lastPage(),
                    'per_page' => $tripulantes->perPage(),
                    'total' => $tripulantes->total(),
                    'from' => $tripulantes->firstItem(),
                    'to' => $tripulantes->lastItem(),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener tripulantes',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Crear un nuevo tripulante.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        try {
            // Obtener el usuario autenticado
            $user = $request->user();

            // Validar los datos de entrada
            $validator = Validator::make($request->all(), [
                'crew_id' => 'required|string|max:10',
                'nombres' => 'required|string|max:50',
                'apellidos' => 'required|string|max:50',
                'pasaporte' => 'nullable|string|max:20',
                'identidad' => 'nullable|string|max:20',
                'posicion' => 'required|integer|exists:posiciones,id_posicion',
                'imagen' => 'nullable|string|max:250',
                'iata_aerolinea' => 'required|string|size:2',
                'id_aerolinea' => 'nullable|integer|exists:aerolineas,id_aerolinea',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Datos de entrada inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Determinar la aerolínea
            $idAerolinea = $request->id_aerolinea;

            // Si el usuario no es admin, usar su aerolínea
            if (!$user->isAdmin()) {
                if (!$user->id_aerolinea) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Usuario sin aerolínea asignada'
                    ], 403);
                }
                $idAerolinea = $user->id_aerolinea;
            }

            // Verificar si ya existe un tripulante con el mismo crew_id en la misma aerolínea
            $existeTripulante = Tripulante::where('crew_id', $request->crew_id)
                ->where('id_aerolinea', $idAerolinea)
                ->exists();

            if ($existeTripulante) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ya existe un tripulante con este crew_id en la aerolínea'
                ], 422);
            }

            // Crear el tripulante
            DB::beginTransaction();

            $tripulante = Tripulante::create([
                'id_aerolinea' => $idAerolinea,
                'iata_aerolinea' => $request->iata_aerolinea,
                'crew_id' => $request->crew_id,
                'nombres' => $request->nombres,
                'apellidos' => $request->apellidos,
                'pasaporte' => $request->pasaporte,
                'identidad' => $request->identidad,
                'posicion' => $request->posicion,
                'imagen' => $request->imagen,
                'fecha_creacion' => now(),
            ]);

            DB::commit();

            // Cargar las relaciones
            $tripulante->load(['aerolinea', 'posicionModel']);

            return response()->json([
                'success' => true,
                'message' => 'Tripulante creado exitosamente',
                'data' => new TripulanteResource($tripulante)
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Error al crear tripulante',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Mostrar un tripulante específico.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $user = $request->user();

            // Buscar el tripulante
            $query = Tripulante::with(['aerolinea', 'posicionModel']);

            // Si el usuario no es admin, solo puede ver tripulantes de su aerolínea
            if (!$user->isAdmin() && $user->id_aerolinea) {
                $query->porAerolinea($user->id_aerolinea);
            }

            $tripulante = $query->findOrFail($id);

            return response()->json([
                'success' => true,
                'message' => 'Tripulante obtenido exitosamente',
                'data' => new TripulanteResource($tripulante)
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Tripulante no encontrado'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener tripulante',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Actualizar un tripulante.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $user = $request->user();

            // Buscar el tripulante
            $query = Tripulante::query();

            // Si el usuario no es admin, solo puede editar tripulantes de su aerolínea
            if (!$user->isAdmin() && $user->id_aerolinea) {
                $query->porAerolinea($user->id_aerolinea);
            }

            $tripulante = $query->findOrFail($id);

            // Validar los datos de entrada
            $validator = Validator::make($request->all(), [
                'crew_id' => 'sometimes|required|string|max:10',
                'nombres' => 'sometimes|required|string|max:50',
                'apellidos' => 'sometimes|required|string|max:50',
                'pasaporte' => 'nullable|string|max:20',
                'identidad' => 'nullable|string|max:20',
                'posicion' => 'sometimes|required|integer|exists:posiciones,id_posicion',
                'imagen' => 'nullable|string|max:250',
                'iata_aerolinea' => 'sometimes|required|string|size:2',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Datos de entrada inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Verificar unicidad del crew_id si se está actualizando
            if ($request->filled('crew_id') && $request->crew_id !== $tripulante->crew_id) {
                $existeTripulante = Tripulante::where('crew_id', $request->crew_id)
                    ->where('id_aerolinea', $tripulante->id_aerolinea)
                    ->where('id_tripulante', '!=', $id)
                    ->exists();

                if ($existeTripulante) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ya existe un tripulante con este crew_id en la aerolínea'
                    ], 422);
                }
            }

            // Actualizar el tripulante
            DB::beginTransaction();

            $tripulante->update($request->only([
                'crew_id',
                'nombres',
                'apellidos',
                'pasaporte',
                'identidad',
                'posicion',
                'imagen',
                'iata_aerolinea',
            ]));

            DB::commit();

            // Cargar las relaciones
            $tripulante->load(['aerolinea', 'posicionModel']);

            return response()->json([
                'success' => true,
                'message' => 'Tripulante actualizado exitosamente',
                'data' => new TripulanteResource($tripulante)
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Tripulante no encontrado'
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar tripulante',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Eliminar un tripulante.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $user = $request->user();

            // Solo admin puede eliminar tripulantes
            if (!$user->isAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permisos para eliminar tripulantes'
                ], 403);
            }

            // Buscar el tripulante
            $tripulante = Tripulante::findOrFail($id);

            DB::beginTransaction();

            $tripulante->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Tripulante eliminado exitosamente'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Tripulante no encontrado'
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar tripulante',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Obtener todas las posiciones disponibles.
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
                'data' => PosicionResource::collection($posiciones)
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener posiciones',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }
}