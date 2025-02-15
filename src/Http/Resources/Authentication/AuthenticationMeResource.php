<?php

namespace RiseTechApps\ApiKey\Http\Resources\Authentication;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthenticationMeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => auth()->user()->getKey() ?? null,
            'email' => auth()->user()->email ?? null,
            'name' => auth()->user()->name ?? null,
            'profile_photo' => $this->getPhoto(),
        ];
    }

    private function getPhoto(): ?string
    {
        try {
            $photo = auth()->user()->getMedia('profile')->first();

            if(is_null($photo)) {
                return null;
            }

            return $photo->getFullUrl();
        } catch (\Exception $e) {
            return null;
        }
    }
}
