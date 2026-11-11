<?php

/*
| Starter text for the website's legal and company pages. Nothing here is
| shown to visitors until an admin reviews it in Admin settings → Pages and
| presses Publish (until then the website keeps its built-in page). Have
| your lawyer check the final wording.
*/

$company = '[Company legal name]';

return [
    'privacy-policy' => [
        'title' => 'Privacy Policy',
        'body' => "<p>{$company} (\"we\") runs this website and its booking services. This policy explains what personal data we collect, why, and your rights.</p>\n<h2>What we collect</h2>\n<ul><li>Name, email, phone and address you give us.</li><li>Traveller details needed by airlines, hotels and other suppliers, including date of birth and passport details for international travel.</li><li>Payment records (we never store full card numbers; payments are handled by our payment partners).</li><li>Device and usage data, such as IP address and pages visited.</li></ul>\n<h2>Why we use it</h2>\n<ul><li>To make, change and cancel your bookings with suppliers.</li><li>To send tickets, invoices and booking updates by email, SMS or WhatsApp.</li><li>To meet legal duties, including tax (GST, TCS) and fraud prevention.</li><li>With your consent, to send offers. You can opt out at any time.</li></ul>\n<h2>Who we share it with</h2>\n<p>Airlines, hotels, transport and other travel suppliers; payment gateways; messaging providers; and government authorities when the law requires it. We do not sell your data.</p>\n<h2>How long we keep it</h2>\n<p>Booking and invoice records are kept for at least 8 years for tax law. Other data is deleted when no longer needed.</p>\n<h2>Your rights</h2>\n<p>You can ask to see, correct or delete your data, or withdraw consent, by writing to [support email]. Under India's Digital Personal Data Protection Act 2023 (and the GDPR for EU/UK visitors) you may also complain to the relevant authority.</p>\n<h2>Contact</h2>\n<p>Grievance officer: [name], [email], [address].</p>",
    ],
    'terms-and-conditions' => [
        'title' => 'Terms & Conditions',
        'body' => "<p>These terms apply to every booking made on this website with {$company}.</p>\n<h2>Our role</h2>\n<p>We arrange travel services supplied by airlines, hotels, bus and cab operators and other suppliers. Each supplier's own conditions of carriage or stay also apply.</p>\n<h2>Prices and payment</h2>\n<p>Prices include the taxes and fees shown at checkout. A booking is confirmed only after payment succeeds and the supplier confirms it. Prices may change until then.</p>\n<h2>Travel documents</h2>\n<p>You are responsible for valid passports, visas, health and identity documents. We are not liable if travel is refused for missing documents.</p>\n<h2>Changes and cancellations</h2>\n<p>Changes and cancellations follow the fare or rate rules and our <a href=\"/cancellation-policy\">Cancellation Policy</a>. Refunds follow our <a href=\"/refund-policy\">Refund Policy</a>.</p>\n<h2>Liability</h2>\n<p>We are not liable for a supplier's delay, cancellation or service failure, but we will help you claim from the supplier.</p>\n<h2>Law</h2>\n<p>These terms are governed by the laws of India; courts in Chennai, Tamil Nadu have jurisdiction.</p>",
    ],
    'refund-policy' => [
        'title' => 'Refund Policy',
        'body' => "<p>When a booking is cancelled, the refund is the amount paid minus the supplier's cancellation charge and our service fee, as shown before you confirm the cancellation.</p>\n<ul><li>Refunds go back to the original payment method (card, UPI, net banking) or your wallet.</li><li>We start the refund within 2 working days of the supplier releasing it. Banks usually take 5 to 7 working days to show it.</li><li>If we could not complete a paid booking, we refund the full amount.</li><li>Convenience fees and payment charges may not be refundable.</li></ul>\n<p>Questions: [support email], [support phone].</p>",
    ],
    'cancellation-policy' => [
        'title' => 'Cancellation Policy',
        'body' => "<p>You can cancel from My Trips or by contacting us. The charge depends on the supplier's rules and how close to travel you cancel; it is shown before you confirm.</p>\n<ul><li>Flights: airline cancellation fee plus our service fee. Non-refundable fares return only government taxes.</li><li>Hotels: free until the deadline shown at booking, then as per the hotel's rules.</li><li>Buses, cabs and activities: as per the operator's rules shown at booking.</li><li>Holiday packages: as per the package's cancellation slabs.</li></ul>",
    ],
    'disclaimer' => [
        'title' => 'Disclaimer',
        'body' => "<p>Information on this website, including prices, schedules and photos, comes from suppliers and is shown in good faith. It may change without notice. {$company} is not responsible for errors in supplier information.</p>",
    ],
    'about-us' => [
        'title' => 'About Us',
        'body' => "<p>{$company} is a travel company based in Chennai, India, offering flights, hotels, buses, cabs, holidays, visas and more to travellers, travel agents and business partners worldwide.</p>",
    ],
];
