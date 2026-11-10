<?php

/**
 * Sellable add-on catalog for flight bookings. Single source of truth for
 * both the API (validates selections against these codes/prices) and the
 * frontend (fetches this list to render the picker) — see
 * FlightBookingController::ancillaryCatalog().
 */
return [
    'meals' => [
        ['code' => 'meal_none', 'label' => 'No meal', 'amount' => '0.00'],
        ['code' => 'meal_veg', 'label' => 'Vegetarian meal', 'amount' => '350.00'],
        ['code' => 'meal_nonveg', 'label' => 'Non-vegetarian meal', 'amount' => '400.00'],
        ['code' => 'meal_diabetic', 'label' => 'Diabetic-friendly meal', 'amount' => '380.00'],
        ['code' => 'meal_jain', 'label' => 'Jain meal', 'amount' => '360.00'],
    ],

    'baggage' => [
        ['code' => 'baggage_none', 'label' => 'No extra baggage', 'amount' => '0.00'],
        ['code' => 'baggage_5kg', 'label' => '+5 kg check-in baggage', 'amount' => '800.00'],
        ['code' => 'baggage_10kg', 'label' => '+10 kg check-in baggage', 'amount' => '1400.00'],
        ['code' => 'baggage_15kg', 'label' => '+15 kg check-in baggage', 'amount' => '1900.00'],
    ],

    'other' => [
        ['code' => 'priority_checkin', 'label' => 'Priority check-in & boarding', 'amount' => '300.00'],
        ['code' => 'lounge_access', 'label' => 'Airport lounge access', 'amount' => '999.00'],
        ['code' => 'travel_pillow', 'label' => 'Travel pillow & blanket kit', 'amount' => '150.00'],
    ],

    'insurance' => [
        [
            'code' => 'insurance_none',
            'label' => 'No thanks',
            'amount' => '0.00',
            'description' => 'Continue without travel insurance.',
        ],
        [
            'code' => 'insurance_basic',
            'label' => 'Basic Travel Protect',
            'amount' => '149.00',
            'description' => 'Covers flight delay/cancellation compensation and lost baggage, up to ₹25,000.',
        ],
        [
            'code' => 'insurance_premium',
            'label' => 'Premium Travel Protect',
            'amount' => '349.00',
            'description' => 'Covers flight delay/cancellation, lost baggage, trip interruption and emergency medical expenses, up to ₹1,00,000.',
        ],
    ],
];
