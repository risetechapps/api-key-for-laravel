<?php

namespace RiseTechApps\ApiKey\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'code' => $this->code,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => !is_null($this->email_verified_at),
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'status' => $this->status,
            'role' => $this->role,
            'locale' => $this->locale,

            // Profile Information
            'profile' => [
                'rg' => $this->rg,
                'cpf' => $this->cpf,
                'birth_date' => $this->birth_date,
                'telephone' => $this->telephone,
                'cellphone' => $this->cellphone,
                'nationality' => $this->nationality,
                'naturalness' => $this->naturalness,
                'marital_status' => $this->marital_status,
            ],

            // Photo
            'photo_url' => $this->when($this->getPhotoProfile(), fn() => $this->getPhotoProfile()->getFullUrl()),

            // Relationships
            'api_key' => $this->whenLoaded('apiKey', fn() => ApiKeyResource::make($this->apiKey)),
            'active_plan' => $this->whenLoaded('activePlan', fn() => UserPlanResource::make($this->activePlan)),
            'address' => $this->whenLoaded('address', $this->address),

            // Usage Statistics
            'usage' => $this->when($request->user()?->id === $this->id, [
                'requests_used' => $this->countUsed(),
                'requests_limit' => $this->requestLimit(),
                'remaining_requests' => max(0, $this->requestLimit() - $this->countUsed()),
            ]),

            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
