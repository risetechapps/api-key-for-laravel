<?php

namespace RiseTechApps\ApiKey\Http\Resources\Authentication;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RiseTechApps\ApiKey\Http\Resources\ApiKeyResource;
use RiseTechApps\ApiKey\Http\Resources\UserPlanResource;

class AuthenticationMeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'code' => $this->code,
            'email' => $this->email,
            'name' => $this->name,
            'status' => $this->status,
            'email_verified' => !is_null($this->email_verified_at),
            'locale' => $this->locale,

            // Profile
            'profile' => [
                'photo_url' => $this->getPhotoUrl(),
                'rg' => $this->rg,
                'cpf' => $this->cpf,
                'birth_date' => $this->birth_date,
                'cellphone' => $this->cellphone,
            ],

            // Relationships
            'api_key' => $this->whenLoaded('apiKey', fn() => ApiKeyResource::make($this->apiKey)),
            'active_plan' => $this->whenLoaded('activePlan', fn() => UserPlanResource::make($this->activePlan)),

            // Usage statistics
            'usage' => [
                'requests_used' => $this->countUsed(),
                'requests_limit' => $this->requestLimit(),
                'remaining_requests' => max(0, $this->requestLimit() - $this->countUsed()),
            ],

            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Get the user's profile photo URL.
     */
    private function getPhotoUrl(): ?string
    {
        try {
            $photo = $this->getMedia('profile')->first();

            if (is_null($photo)) {
                return null;
            }

            return $photo->getFullUrl();
        } catch (\Exception $e) {
            return null;
        }
    }
}
