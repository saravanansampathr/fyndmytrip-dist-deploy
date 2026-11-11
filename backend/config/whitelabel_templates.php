<?php

/**
 * The white-label "choose a template" gallery — a starting look a
 * White-Label Partner or Agent picks for their own storefront. Each entry
 * now does three real jobs, not two:
 *
 * 1. `accent_color` — unchanged — a one-click prefill for the "Brand theme"
 *    color picker on WhiteLabelSettingsPage (the fully-custom color/font/
 *    button-shape engine is still what actually renders everywhere else;
 *    see WhiteLabelController::update()'s docblock).
 *
 * 2. `homepage_hero` + `homepage_flow` — which hero-band treatment
 *    HomeHero.tsx shows (lib/homeLayout.ts's HomeHeroVariant —
 *    classic/photo/split/centered/minimal/bold) and which visual treatment
 *    the rest of HomePage.tsx's sections use (HomeFlowVariant —
 *    cards/compact/showcase/magazine).
 *
 * 3. `homepage_search_widget` — NEW. "Templates need like multiple cards or
 *    new search bars... completely different UI. End to end new 10+ UI"
 *    (Platform Roadmap): until this existed, every template rendered the
 *    EXACT SAME search-widget markup (same trip-type pills, same 3-box
 *    field row) inside HomeHero's `children` — hero/flow only ever changed
 *    the band and the sections below it, never the search form itself.
 *    This key picks which of 12 structurally different widgets
 *    HomeSearchWidget.tsx renders (lib/homeLayout.ts's
 *    HomeSearchWidgetVariant — see that file's docblock for what each one
 *    actually looks like). Deliberately named by STRUCTURE only (segmented
 *    toolbar, icon tabs, floating capsule, ...), never by the real OTA it
 *    was visually inspired by — no trademarked name, logo or exact styling
 *    is reused anywhere, only FyndMyTrip's own colors/fonts/copy.
 *
 * Each of the 12 entries below now pairs a DIFFERENT (hero, flow, search
 * widget) triple — see the tables in lib/homeLayout.ts's docblock — so
 * every template reads as a genuinely different homepage: a different hero
 * band, a different section treatment, AND a different search-form
 * structure, not just a different accent color on the same layout.
 *
 * Kept here (not a DB table) since the catalog is platform-wide and changes
 * at deploy time, same pattern as config/suppliers.php's credential_fields.
 */
return [
    ['id' => 1, 'name' => 'Classic Navy', 'description' => 'The default FyndMyTrip look — navy header, teal accents, the original tabbed search card.', 'accent_color' => '#0f2a4a', 'homepage_hero' => 'classic', 'homepage_flow' => 'cards', 'homepage_search_widget' => 'tabbed_card'],
    ['id' => 2, 'name' => 'Sunrise Coral', 'description' => 'Warm coral/orange accents, big photo-led hero, a deck of elevated search-field cards — leisure & holiday-led brands.', 'accent_color' => '#ff6b4a', 'homepage_hero' => 'photo', 'homepage_flow' => 'showcase', 'homepage_search_widget' => 'card_deck'],
    ['id' => 3, 'name' => 'Emerald Business', 'description' => 'Deep green + gold, quiet minimal hero, dense data-forward sections, flat underline search tabs — corporate travel desks.', 'accent_color' => '#0f6f4f', 'homepage_hero' => 'minimal', 'homepage_flow' => 'compact', 'homepage_search_widget' => 'underline_tabs'],
    ['id' => 4, 'name' => 'Midnight Slate', 'description' => 'Bold solid-color hero block with a diagonal edge, compact sections, a single segmented search toolbar — tech-forward OTAs.', 'accent_color' => '#3b82f6', 'homepage_hero' => 'bold', 'homepage_flow' => 'compact', 'homepage_search_widget' => 'segmented_bar'],
    ['id' => 5, 'name' => 'Royal Purple', 'description' => 'Centered radial hero, premium image-led showcase sections, a two-tone split search panel — luxury travel brands.', 'accent_color' => '#7c3aed', 'homepage_hero' => 'centered', 'homepage_flow' => 'showcase', 'homepage_search_widget' => 'dual_tone'],
    ['id' => 6, 'name' => 'Desert Sand', 'description' => 'Two-panel split hero, classic card sections, a colored side-rail search panel — Middle East & Gulf-market storefronts.', 'accent_color' => '#c2793a', 'homepage_hero' => 'split', 'homepage_flow' => 'cards', 'homepage_search_widget' => 'sidebar_rail'],
    ['id' => 7, 'name' => 'Crimson Express', 'description' => 'Bold red diagonal hero, big icon-tab search switcher, classic card sections — high-conversion low-cost carriers style.', 'accent_color' => '#dc2626', 'homepage_hero' => 'bold', 'homepage_flow' => 'cards', 'homepage_search_widget' => 'icon_tabs'],
    ['id' => 8, 'name' => 'Ocean Teal', 'description' => 'Quiet minimal hero, lots of white space, a borderless floating-capsule search bar — boutique agencies.', 'accent_color' => '#0d9488', 'homepage_hero' => 'minimal', 'homepage_flow' => 'cards', 'homepage_search_widget' => 'floating_pill'],
    ['id' => 9, 'name' => 'Sunny Yellow', 'description' => 'Bold solid-color hero, editorial asymmetric sections, a numbered step-by-step search flow — playful budget/backpacker-focused brands.', 'accent_color' => '#eab308', 'homepage_hero' => 'bold', 'homepage_flow' => 'magazine', 'homepage_search_widget' => 'wizard_steps'],
    ['id' => 10, 'name' => 'Steel Grey Corporate', 'description' => 'Two-panel split hero, dense data-forward sections, a boxed search grid — B2B/API-partner dashboards.', 'accent_color' => '#475569', 'homepage_hero' => 'split', 'homepage_flow' => 'compact', 'homepage_search_widget' => 'boxed_grid'],
    ['id' => 11, 'name' => 'Rose Gold', 'description' => 'Centered radial hero, editorial asymmetric sections, a narrow portrait-stacked search form — honeymoon & wedding-travel niches.', 'accent_color' => '#e0a8a0', 'homepage_hero' => 'centered', 'homepage_flow' => 'magazine', 'homepage_search_widget' => 'stacked_vertical'],
    ['id' => 12, 'name' => 'Forest Adventure', 'description' => 'Big photo-led hero, editorial asymmetric sections, one dense compact-line search bar — adventure/trekking brands.', 'accent_color' => '#4d7c0f', 'homepage_hero' => 'photo', 'homepage_flow' => 'magazine', 'homepage_search_widget' => 'compact_bar'],
];
