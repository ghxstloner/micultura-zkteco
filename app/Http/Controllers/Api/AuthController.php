<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\SecUser;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    /**
     * Inicio de sesión del usuario.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function login(Request $request): JsonResponse
    {
        try {
            // Validar los datos de entrada
            $validator = Validator::make($request->all(), [
                'login' => 'required|string|max:255',
                'password' => 'required|string|min:1',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Datos de entrada inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Buscar el usuario por login
            $user = SecUser::with('aerolinea')
                ->where('login', $request->login)
                ->first();

            // Verificar si el usuario existe
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Credenciales incorrectas'
                ], 401);
            }

            // Verificar si el usuario está activo
            if (!$user->isActive()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario inactivo. Contacte al administrador.'
                ], 401);
            }

            // Verificar la contraseña
            if (!$user->checkPassword($request->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Credenciales incorrectas'
                ], 401);
            }

            // Revocar tokens existentes (opcional - descomenta si quieres sesión única)
            // $user->tokens()->delete();

            // Crear nuevo token
            $token = $user->createToken('auth-token', ['*'], now()->addDays(30))->plainTextToken;

            // Actualizar última vez que se cambió la contraseña si es necesario
            if (!$user->pswd_last_updated) {
                $user->pswd_last_updated = now();
                $user->save();
            }

            return response()->json([
                'success' => true,
                'message' => 'Inicio de sesión exitoso',
                'data' => [
                    'user' => new UserResource($user),
                    'token' => $token,
                    'token_type' => 'Bearer',
                    'expires_at' => now()->addDays(30)->toDateTimeString()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Cerrar sesión del usuario.
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
                // Revocar el token actual
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
     * Obtener la información del usuario autenticado.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function me(Request $request): JsonResponse
    {
        try {
            $user = $request->user()->load('aerolinea');

            return response()->json([
                'success' => true,
                'message' => 'Información del usuario obtenida exitosamente',
                'data' => new UserResource($user)
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
     * Refrescar el token de acceso.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function refresh(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            // Revocar el token actual
            $request->user()->currentAccessToken()->delete();

            // Crear nuevo token
            $token = $user->createToken('auth-token', ['*'], now()->addDays(30))->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Token refrescado exitosamente',
                'data' => [
                    'token' => $token,
                    'token_type' => 'Bearer',
                    'expires_at' => now()->addDays(30)->toDateTimeString()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al refrescar token',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }
}