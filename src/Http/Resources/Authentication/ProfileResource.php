<?php

namespace RiseTechApps\ApiKey\Http\Resources\Authentication;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            'rg' => $this->rg,
            'cpf' => $this->cpf,
            'birth_date' => $this->birth_date,
            'telephone' => $this->telephone,
            'cellphone' => $this->cellphone,
            'genre' => $this->genre,
            'nationality' => $this->nationality,
            'naturalness' => $this->naturalness,
            'marital_status' => $this->marital_status,
            'address' => $this->address,
            'email' => $this->email,
            'photo' => $this->getPhotoProfile()
        ];
    }
}
