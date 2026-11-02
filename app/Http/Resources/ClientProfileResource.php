<?php

namespace App\Http\Resources;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a client may see about themselves. The tracking token, the id and the agency's
 * remarks are left out on purpose.
 *
 * @mixin Client
 */
class ClientProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            'phone' => $this->phone,
            'birthday' => $this->birthday->toDateString(),
            'gender' => $this->gender->value,
            'photo_url' => $this->photoUrl(),
        ];
    }
}
