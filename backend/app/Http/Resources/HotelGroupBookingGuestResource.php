<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Domains\Booking\Models\HotelGroupBookingGuest
 */
class HotelGroupBookingGuestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'room_number' => $this->room_number,
            'title' => $this->title,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'guest_type' => $this->guest_type,
            'dob' => $this->dob?->toDateString(),
            'gender' => $this->gender,
            'id_proof_type' => $this->id_proof_type,
            'id_proof_number' => $this->id_proof_number,
        ];
    }
}
