<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Usamos Schema::table() para modificar una tabla existente.
        Schema::table('tripulantes_solicitudes', function (Blueprint $table) {
            $table->string('email')->unique()->after('apellidos');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // El método down() revierte lo que hicimos en el método up().
        Schema::table('tripulantes_solicitudes', function (Blueprint $table) {
            // Eliminamos la columna 'email' si se necesita revertir la migración.
            $table->dropColumn('email');
        });
    }
};
