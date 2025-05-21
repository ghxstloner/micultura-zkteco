<?php

namespace App\Services\ZKTeco\ProFaceX\Manager;

use App\Models\ZKTeco\ProFaceX\ProFxDeviceCommand;
use App\Models\ZKTeco\ProFaceX\ProFxUserInfo;
use App\Services\ZKTeco\ProFaceX\Constants;
use App\Services\ZKTeco\ProFaceX\DevCmdUtil;
use App\Services\ZKTeco\ProFaceX\PushUtil;

class DeviceCommandManager
{
    public function getDeviceCommandById($devCmdId)
    {
        return ProFxDeviceCommand::find($devCmdId);
    }

    public function createINFOCommand(string $deviceSn): void
    {
        $deviceCommandModel = DevCmdUtil::getINFOCommand($deviceSn);
        $deviceCommandModel->save();
    }

    public function createClearAllDataCommand(string $deviceSn): void
    {
        $deviceCommandModel = DevCmdUtil::getClearAllDataCommand($deviceSn);
        $deviceCommandModel->save();
    }

    public function createRebootCommand(string $deviceSn): void
    {
        $deviceCommandModel = DevCmdUtil::createRebootCommand($deviceSn);
        $deviceCommandModel->save();
    }

    public function createDeleteUserCommandByIds(array $userIds): void
    {
        $userInfoList = ProFxUserInfo::whereIn('USER_ID', $userIds)->get();
        foreach($userInfoList as $userInfo) {
            $deviceCommandModel = DevCmdUtil::getDeleteUserCommand($userInfo);
            $deviceCommandModel->save();
        }
    }

    public function getDeviceCommandListToDevice(string $deviceSn)
    {
        return ProFxDeviceCommand::where('DEVICE_SN', $deviceSn)
            ->where('CMD_RETURN', '')
            ->orderBy('DEV_CMD_ID', 'asc')
            ->skip(0)->take(100)->get();
    }

    public static function updateDeviceCommand(array $commandModelList = []): void
    {
        foreach ($commandModelList as $commandModel) {
            $commandModel->save();
        }
    }

    /**
     * Crea un comando de actualización de datos de usuario del dispositivo según el ID de usuario.
     * - Se enviará el comando de actualización de la plantilla de huella dactilar al dispositivo si la función de huella dactilar es compatible en el dispositivo.
     * - Se enviará el comando de actualización de la foto del usuario al dispositivo si la función de foto de usuario es compatible en el dispositivo.
     * - Se enviará el comando de actualización de la plantilla facial al dispositivo si la función de reconocimiento facial es compatible en el dispositivo.
     *
     * @param ProFxUserInfo $userInfo
     * @param string|null $DEV_FUNS
     * @return int
     */
    public static function createUpdateUserInfosCommandByIds(ProFxUserInfo $userInfo, string $DEV_FUNS): int
    {
        // Log inicial con datos del usuario
        Log::info("Iniciando createUpdateUserInfosCommandByIds para USER_PIN: {$userInfo->USER_PIN}, DEVICE_SN: {$userInfo->DEVICE_SN}");

        // Verificar las funciones que soporta el dispositivo
        $isSupportUserPic = PushUtil::isDevFun($DEV_FUNS, Constants::DEV_FUNS['USERPIC']);
        $isSupportBioPhoto = PushUtil::isDevFun($DEV_FUNS, Constants::DEV_FUNS['BIOPHOTO']);

        // Log de capacidades del dispositivo
        Log::info("Capacidades del dispositivo {$userInfo->DEVICE_SN}: USERPIC=" . ($isSupportUserPic ? 'SI' : 'NO') .
                ", BIOPHOTO=" . ($isSupportBioPhoto ? 'SI' : 'NO'));

        // Log de información de la foto
        if ($userInfo->PHOTO_ID_NAME) {
            Log::info("Información de foto para USER_PIN {$userInfo->USER_PIN}: PHOTO_ID_NAME={$userInfo->PHOTO_ID_NAME}, PHOTO_ID_SIZE={$userInfo->PHOTO_ID_SIZE}");
        } else {
            Log::info("Usuario {$userInfo->USER_PIN} no tiene foto configurada");
        }

        try {
            $comandos = [];

            // Crear el comando de actualización de la información del usuario
            Log::info("Creando comando UPDATE USERINFO para USER_PIN: {$userInfo->USER_PIN}");
            $comandos[] = DevCmdUtil::getUpdateUserCommand($userInfo, $userInfo->DEVICE_SN);

            // Crear el comando de actualización de la foto del usuario
            if ($isSupportUserPic && null !== $userInfo->PHOTO_ID_NAME && !empty($userInfo->PHOTO_ID_NAME)) {
                Log::info("Creando comando UPDATE USERPIC para USER_PIN: {$userInfo->USER_PIN}, tamaño foto: {$userInfo->PHOTO_ID_SIZE}");
                $comandos[] = DevCmdUtil::getUpdateUserPicCommand($userInfo, $userInfo->DEVICE_SN);
            } else {
                Log::info("No se crea comando USERPIC: " .
                        "Soporte USERPIC=" . ($isSupportUserPic ? 'SI' : 'NO') .
                        ", PHOTO_ID_NAME=" . ($userInfo->PHOTO_ID_NAME ? $userInfo->PHOTO_ID_NAME : 'NULL'));
            }

            // Crear el comando de actualización de la foto biométrica del usuario
            if ($isSupportBioPhoto && null !== $userInfo->PHOTO_ID_NAME && !empty($userInfo->PHOTO_ID_NAME)) {
                Log::info("Creando comando UPDATE BIOPHOTO para USER_PIN: {$userInfo->USER_PIN}, tamaño foto: {$userInfo->PHOTO_ID_SIZE}");
                $comandos[] = DevCmdUtil::getUpdateBioPhotoCommand($userInfo, $userInfo->DEVICE_SN);
            } else {
                Log::info("No se crea comando BIOPHOTO: " .
                        "Soporte BIOPHOTO=" . ($isSupportBioPhoto ? 'SI' : 'NO') .
                        ", PHOTO_ID_NAME=" . ($userInfo->PHOTO_ID_NAME ? $userInfo->PHOTO_ID_NAME : 'NULL'));
            }

            Log::info("Total comandos creados para USER_PIN {$userInfo->USER_PIN}: " . count($comandos));
            ManagerFactory::getCommandManager()->updateDeviceCommand($comandos);
        } catch (\Exception $e) {
            Log::error("Error en createUpdateUserInfosCommandByIds para USER_PIN {$userInfo->USER_PIN}: {$e->getMessage()}");
            Log::error("Trace: {$e->getTraceAsString()}");
            return 1;
        }

        Log::info("Finalizado createUpdateUserInfosCommandByIds para USER_PIN: {$userInfo->USER_PIN}");
        return 0;
    }

}
