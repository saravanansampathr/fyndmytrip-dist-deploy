<?php

/**
 * Platform Roadmap Phase 5 — "Airline check-in deep link on flight
 * e-tickets. A lookup table mapping airline code -> that airline's real
 * online check-in URL." Keyed by the 2-character IATA carrier code (the
 * prefix of every flight number, e.g. "6E-2341" -> "6E") — see
 * App\Support\AirlineCheckIn::deriveCode().
 *
 * Deliberately NOT exhaustive: only carriers this has been able to verify
 * a real, current check-in URL for. An unlisted code simply gets no
 * "Check in online" button rather than a guessed/broken link — same rule
 * as every other branding/contact field in these PDFs (never show a
 * fake/placeholder value).
 *
 * None of these carriers' public check-in pages are confirmed to accept a
 * PNR via URL/query parameter (most require it typed into a form), so this
 * links to the carrier's check-in tool and the PDF/booking-detail view
 * shows the PNR right alongside it for the traveller to enter — rather
 * than appending an unverified query param that might silently not work.
 * A carrier whose URL format IS confirmed to accept one can add a
 * `pnr_param` key here (e.g. 'pnr') and the traveller's PNR is appended as
 * that query parameter automatically — see AirlineCheckIn::checkInUrl().
 */
return [
    '6E' => ['name' => 'IndiGo', 'checkin_url' => 'https://www.goindigo.in/web-check-in.html'],
    'AI' => ['name' => 'Air India', 'checkin_url' => 'https://www.airindia.com/in/en/manage.html'],
    // Vistara completed its merger into Air India — legacy UK-coded
    // bookings are managed through Air India's own portal now.
    'UK' => ['name' => 'Vistara (Air India)', 'checkin_url' => 'https://www.airindia.com/in/en/manage.html'],
    'SG' => ['name' => 'SpiceJet', 'checkin_url' => 'https://www.spicejet.com/checkin'],
    'QP' => ['name' => 'Akasa Air', 'checkin_url' => 'https://www.akasaair.com/check-in/web-check-in'],
    'IX' => ['name' => 'Air India Express', 'checkin_url' => 'https://www.airindiaexpress.com/checkin-home'],
    'EK' => ['name' => 'Emirates', 'checkin_url' => 'https://www.emirates.com/us/english/manage-booking/online-check-in/'],
    'QR' => ['name' => 'Qatar Airways', 'checkin_url' => 'https://www.qatarairways.com/en-us/services-checking-in.html'],
    'EY' => ['name' => 'Etihad Airways', 'checkin_url' => 'https://www.etihad.com/en-us/manage/check-in'],
    'SQ' => ['name' => 'Singapore Airlines', 'checkin_url' => 'https://www.singaporeair.com/en_UK/sg/travel-info/check-in/online-mobile-checkin/'],
];
