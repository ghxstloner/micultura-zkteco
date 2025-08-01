<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PosicionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id_posicion' => $this->id_posicion,
            'codigo_posicion' => $this->codigo_posicion,
            'descripcion' => $this->descripcion,
        ];
    }
}