<?php

namespace App\Models\ZKTeco\ProFaceX;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class ProFxDeviceCommand extends Model
{
    /*
    |--------------------------------------------------------------------------
    | GLOBAL VARIABLES
    |--------------------------------------------------------------------------
    */

    /**
     * The table associated with the model.
     *
     * @var string
     */
     protected $table = 'profacex_device_command';

    /**
     * The primary key for the model.
     *
     * @var string
     */
     protected $primaryKey = 'DEV_CMD_ID';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var boolean
     */
    // public $timestamps = false;

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    // protected $guarded = ['id'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    // protected $fillable = [];

    /**
     * The attributes that should be hidden for arrays
     *
     * @var array
     */
    // protected $hidden = [];



    /*
    |--------------------------------------------------------------------------
    | FUNCTIONS
    |--------------------------------------------------------------------------
    */

    /**
     * The "booted" method of the model.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($model) {
            // Format dates without milliseconds
            if (!empty($model->CMD_COMMIT_TIMES)) {
                $model->CMD_COMMIT_TIMES = Carbon::parse($model->CMD_COMMIT_TIMES)->format('Y-m-d H:i:s');
            }
            if (!empty($model->CMD_TRANS_TIMES)) {
                $model->CMD_TRANS_TIMES = Carbon::parse($model->CMD_TRANS_TIMES)->format('Y-m-d H:i:s');
            }
            if (!empty($model->CMD_OVER_TIME)) {
                $model->CMD_OVER_TIME = Carbon::parse($model->CMD_OVER_TIME)->format('Y-m-d H:i:s');
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | RELATIONS
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | SCOPES
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | ACCESSORS
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | MUTATORS
    |--------------------------------------------------------------------------
    */
}
