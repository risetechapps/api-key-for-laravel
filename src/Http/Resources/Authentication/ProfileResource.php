<?php

namespace RiseTechApps\ApiKey\Http\Resources\Authentication;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RiseTechApps\ApiKey\Http\Resources\ApiKeyResource;
use RiseTechApps\ApiKey\Http\Resources\UserPlanResource;

class ProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            // Personal Information
            'personal' => [
                'name' => $this->name,
                'rg' => $this->rg,
                'cpf' => $this->cpf,
                'birth_date' => $this->birth_date,
                'genre' => $this->genre,
                'nationality' => $this->nationality,
                'naturalness' => $this->naturalness,
                'marital_status' => $this->marital_status,
            ],

            // Contact Information
            'contact' => [
                'email' => $this->email,
                'telephone' => $this->telephone,
                'cellphone' => $this->cellphone,
            ],

            // Address
            'address' => $this->whenLoaded('address', $this->address),

            // Media
            'photo' => [
                'url' => $this->when($this->getPhotoProfile(), fn() => $this->getPhotoProfile()->getFullUrl()),
            ],

            // Account Information
            'account' => [
                'id' => $this->getKey(),
                'code' => $this->code,
                'status' => $this->status,
                'locale' => $this->locale,
                'email_verified' => !is_null($this->email_verified_at),
                'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            ],

            // Relationships
            'api_key' => $this->whenLoaded('apiKey', fn() => ApiKeyResource::make($this->apiKey)),
            'active_plan' => $this->whenLoaded('activePlan', fn() => UserPlanResource::make($this->activePlan)),

            // Usage Statistics
            'usage' => [
                'requests_used' => $this->countUsed(),
                'requests_limit' => $this->requestLimit(),
                'remaining_requests' => max(0, $this->requestLimit() - $this->countUsed()),
            ],

            // Timestamps
            'timestamps' => [
                'created_at' => $this->created_at?->toIso8601String(),
                'updated_at' => $this->updated_at?->toIso8601String(),
            ],
        ];
    }
}
