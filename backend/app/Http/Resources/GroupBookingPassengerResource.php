<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Domains\Booking\Models\GroupBookingPassenger
 */
class GroupBookingPassengerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'passenger_type' => $this->passenger_type,
            'dob' => $this->dob?->toDateString(),
            'gender' => $this->gender,
            'passport_number' => $this->passport_number,
            'passport_expiry_date' => $this->passport_expiry_date?->toDateString(),
        ];
    }
}
