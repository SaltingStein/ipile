<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public profile of the authenticated user returned by the API.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->display_name,
            'nickname' => $this->nickname,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'avatar_url' => $this->avatar_url,
            'is_guest' => $this->is_guest,
            'has_password' => $this->hasPassword(),
            'two_factor_enabled' => $this->hasEnabledTwoFactorAuthentication(),
            'roles' => $this->getRoleNames(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
