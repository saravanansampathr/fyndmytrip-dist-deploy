<?php

/**
 * The curated Google Fonts catalog offered by the "Brand theme" picker
 * (Platform Roadmap's theming-engine ask: "fully custom" font/color/button
 * control per tenant, replacing the old fixed 12-preset template_id).
 * `google_param` is the Google Fonts CSS2 `family=` value the frontend
 * requests to load the font at runtime; `stack` is the CSS font-family
 * value applied to <body>, with safe generic fallbacks in case the Google
 * Fonts stylesheet fails to load. Keyed here (not a DB table) for the same
 * reason config/whitelabel_templates.php is — a platform-wide catalog that
 * changes at deploy time, not per-tenant data.
 */
return [
    'inter' => ['label' => 'Inter (default)', 'google_param' => 'Inter:wght@400;500;600;700', 'stack' => "'Inter', ui-sans-serif, system-ui, sans-serif"],
    'poppins' => ['label' => 'Poppins', 'google_param' => 'Poppins:wght@400;500;600;700', 'stack' => "'Poppins', ui-sans-serif, system-ui, sans-serif"],
    'montserrat' => ['label' => 'Montserrat', 'google_param' => 'Montserrat:wght@400;500;600;700', 'stack' => "'Montserrat', ui-sans-serif, system-ui, sans-serif"],
    'roboto' => ['label' => 'Roboto', 'google_param' => 'Roboto:wght@400;500;700', 'stack' => "'Roboto', ui-sans-serif, system-ui, sans-serif"],
    'nunito' => ['label' => 'Nunito', 'google_param' => 'Nunito:wght@400;600;700;800', 'stack' => "'Nunito', ui-sans-serif, system-ui, sans-serif"],
    'lato' => ['label' => 'Lato', 'google_param' => 'Lato:wght@400;700', 'stack' => "'Lato', ui-sans-serif, system-ui, sans-serif"],
    'work_sans' => ['label' => 'Work Sans', 'google_param' => 'Work+Sans:wght@400;500;600;700', 'stack' => "'Work Sans', ui-sans-serif, system-ui, sans-serif"],
    'quicksand' => ['label' => 'Quicksand', 'google_param' => 'Quicksand:wght@400;500;600;700', 'stack' => "'Quicksand', ui-sans-serif, system-ui, sans-serif"],
    'rubik' => ['label' => 'Rubik', 'google_param' => 'Rubik:wght@400;500;600;700', 'stack' => "'Rubik', ui-sans-serif, system-ui, sans-serif"],
    'playfair_display' => ['label' => 'Playfair Display', 'google_param' => 'Playfair+Display:wght@500;600;700', 'stack' => "'Playfair Display', ui-serif, Georgia, serif"],
];
