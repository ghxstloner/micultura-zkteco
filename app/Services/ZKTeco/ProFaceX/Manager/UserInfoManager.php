<?php

namespace App\Services\ZKTeco\ProFaceX\Manager;

use App\Models\ZKTeco\ProFaceX\ProFxUserInfo;
use Illuminate\Support\Facades\Log;

class UserInfoManager
{
    public function createUserInfo($userInfoList = [])
    {
        foreach ($userInfoList as $useList) {
            // Skip items with empty USER_PIN to prevent database errors
            if (empty($useList->USER_PIN)) {
                Log::error("Skipping save for user info with empty USER_PIN");
                continue;
            }
            $useList->save();
        }
    }

    public function updateUserPic($userInfoList = [])
    {
        $this->createUserInfo($userInfoList);

        return $userInfoList;
    }

    public function getUserInfoByPinAndSn(?string $userPin, ?string $deviceSn)
    {
        // Ensure we don't query with null values
        if (empty($userPin) || empty($deviceSn)) {
            Log::warning("Cannot get user info with empty USER_PIN or DEVICE_SN");
            return null;
        }

        $info = ProFxUserInfo::where('USER_PIN', $userPin)->where('DEVICE_SN', $deviceSn)->first();
        if (!$info) {
            return null;
        }

        return $info;
    }
}
