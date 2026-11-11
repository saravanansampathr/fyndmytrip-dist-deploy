"""Single source for the public site's SEO pages.

Used by tools/booking-ux/patch_booking_ux.py (route lists and landing-page
text compiled into the portal bundle, so footer links, landing pages and
in-app titles agree) and by tools/seo/build_seo.py (pre-rendered HTML for
crawlers, sitemap.xml, robots.txt).

Only facts that are true for any supplier go here: airport names, what the
site does, and how booking works. No invented durations, prices or airline
schedules; live fares come from the search itself.
"""

BRAND = 'FyndMyTrip'

AIRPORTS = {
    'DEL': ('Delhi', 'Indira Gandhi International Airport'),
    'BOM': ('Mumbai', 'Chhatrapati Shivaji Maharaj International Airport'),
    'BLR': ('Bengaluru', 'Kempegowda International Airport'),
    'MAA': ('Chennai', 'Chennai International Airport'),
    'HYD': ('Hyderabad', 'Rajiv Gandhi International Airport'),
    'CCU': ('Kolkata', 'Netaji Subhas Chandra Bose International Airport'),
    'GOI': ('Goa', 'Goa International Airport (Dabolim)'),
    'COK': ('Kochi', 'Cochin International Airport'),
    'AMD': ('Ahmedabad', 'Sardar Vallabhbhai Patel International Airport'),
    'PNQ': ('Pune', 'Pune Airport'),
    'JAI': ('Jaipur', 'Jaipur International Airport'),
    'DXB': ('Dubai', 'Dubai International Airport'),
    'SIN': ('Singapore', 'Singapore Changi Airport'),
    'LHR': ('London', 'London Heathrow Airport'),
    'BKK': ('Bangkok', 'Suvarnabhumi Airport'),
}
INTERNATIONAL = {'DXB', 'SIN', 'LHR', 'BKK'}

# The first ten are the footer's "Popular routes" (same order as before).
FLIGHT_ROUTES = [
    ('DEL', 'BOM'), ('BOM', 'BLR'), ('MAA', 'BLR'), ('DEL', 'GOI'), ('BLR', 'HYD'),
    ('CCU', 'DEL'), ('BOM', 'DXB'), ('DEL', 'DXB'), ('DEL', 'SIN'), ('BOM', 'LHR'),
    ('BOM', 'DEL'), ('DEL', 'BLR'), ('BLR', 'DEL'), ('MAA', 'DEL'), ('MAA', 'BOM'),
    ('DEL', 'HYD'), ('BOM', 'GOI'), ('COK', 'BLR'), ('AMD', 'BOM'), ('MAA', 'DXB'),
    ('MAA', 'SIN'), ('DEL', 'BKK'), ('BLR', 'DXB'), ('HYD', 'DXB'),
]
BUS_ROUTES = [
    ('Chennai', 'Bengaluru'), ('Mumbai', 'Pune'), ('Delhi', 'Jaipur'), ('Hyderabad', 'Bengaluru'),
    ('Coimbatore', 'Chennai'), ('Ahmedabad', 'Mumbai'), ('Bengaluru', 'Chennai'), ('Pune', 'Mumbai'),
    ('Chennai', 'Madurai'), ('Bengaluru', 'Hyderabad'), ('Delhi', 'Manali'), ('Chennai', 'Coimbatore'),
]
HOTEL_CITIES = ['Goa', 'Jaipur', 'Manali', 'Kochi', 'Udaipur', 'Shimla', 'Chennai', 'Bengaluru', 'Mumbai', 'Delhi', 'Ooty', 'Munnar', 'Pondicherry', 'Dubai']

FOOTER_FLIGHTS, FOOTER_BUSES, FOOTER_HOTELS = 10, 6, 6


def slug(text):
    out = ''.join(c.lower() if c.isalnum() else '-' for c in text)
    while '--' in out:
        out = out.replace('--', '-')
    return out.strip('-')


def flight_path(o, d):
    return f'/flights/{slug(AIRPORTS[o][0])}-to-{slug(AIRPORTS[d][0])}-flights'


def bus_path(o, d):
    return f'/buses/{slug(o)}-to-{slug(d)}-bus-tickets'


def hotel_path(city):
    return f'/hotels/hotels-in-{slug(city)}'


SERVICE_PAGES = {
    '/': dict(
        title=f'{BRAND}: Book Flights, Hotels, Bus Tickets & Holiday Packages Online',
        description='Compare live fares and book flights, hotels, bus tickets, cabs, holiday packages, cruises, activities, travel insurance and visas. Clear prices, secure payment and 24/7 help.',
        h1='Flights, hotels, buses and holidays, booked in minutes',
        intro=[
            f'{BRAND} brings every part of a trip into one place. Search live fares across airlines, compare hotels and bus operators, and pay once with UPI, cards, netbanking or your wallet.',
            'Every price you see includes taxes. Your e-ticket or voucher reaches your email and mobile as soon as you pay.',
        ],
        faqs=[
            ('Can I book without creating an account?', 'Yes. Flights, hotels, buses and holiday packages can be booked as a guest with just an email and mobile number. Sign in if you want to use your wallet balance or see all your trips in one place.'),
            ('How do I pay?', 'You can pay by UPI, credit or debit card, netbanking, international cards or your wallet balance. Payments go through a secure, PCI-DSS compliant gateway.'),
            ('Where do I find my ticket?', 'Your e-ticket or voucher is emailed to you and can be downloaded from the confirmation page. Signed-in customers also find it under My Trips.'),
            ('I am a travel agent. Can I book for my clients?', f'Yes. Apply for a B2B account on our Partner page to get agent fares, a credit wallet, your own markups and a white-label booking site.'),
        ],
    ),
    '/flights': dict(
        title='Flight Booking: Cheap Domestic & International Flight Tickets | ' + BRAND,
        description='Compare live airfares across airlines for one-way, round-trip and multi-city flights. Student, senior citizen and armed forces fares, seat selection, meals and instant e-tickets.',
        h1='Book flight tickets',
        intro=['Search one-way, round-trip and multi-city flights across airlines and compare fares side by side. Filter by stops, timings, airline and refundable fares, then pick seats, meals and extra baggage before you pay.'],
        faqs=[
            ('Do the fares include taxes?', 'Yes. The price on each flight is the total per traveller including airline taxes and fees. The fare summary at checkout shows the full breakup before you pay.'),
            ('Can I choose my seat and meal?', 'Yes. After entering traveller details you can pick seats from the seat map and add meals, extra baggage and other services offered by the airline.'),
            ('Are special fares available?', 'Student, senior citizen and armed forces fares are offered where the airline supports them. Carry the matching ID proof when you travel.'),
            ('When does web check-in open?', 'Most airlines open web check-in 48 hours before departure for domestic flights and 24 hours for international flights. The confirmation page links to the airline check-in page.'),
        ],
    ),
    '/hotels': dict(
        title='Hotel Booking: Best Deals on Hotels in India & Abroad | ' + BRAND,
        description='Find and book hotels and resorts with room options, free cancellation choices and instant vouchers. Pay at checkout with UPI, cards or wallet.',
        h1='Book hotels',
        intro=['Compare hotels by price and star rating, and filter for free cancellation. Room options show what is included, whether you can cancel for free and the total price for your stay.'],
        faqs=[
            ('Is the price per night or for the whole stay?', 'Results show the price for your dates. The checkout summary shows the total for every room and night, with taxes included.'),
            ('What do I show at check-in?', 'Show your hotel voucher, on your phone or printed, along with a government photo ID for each adult guest.'),
            ('Can I cancel my hotel booking?', 'Each room shows its cancellation rules before you book. Free-cancellation rooms can be cancelled from My Trips until the deadline shown.'),
        ],
    ),
    '/buses': dict(
        title='Bus Ticket Booking: AC, Sleeper & Volvo Buses Online | ' + BRAND,
        description='Book bus tickets across India. Compare operators, choose seats from the live seat map and get an instant m-ticket.',
        h1='Book bus tickets',
        intro=['Compare AC, non-AC, sleeper and seater buses from many operators. Choose your exact seat from the seat map and get an m-ticket on your phone.'],
        faqs=[
            ('Do I need a printout of my bus ticket?', 'No. Show the m-ticket on your phone along with a photo ID when you board.'),
            ('What are ladies seats?', 'Some operators reserve seats for women travellers. They are marked on the seat map and can only be booked for female passengers.'),
        ],
    ),
    '/cabs': dict(
        title='Cab Booking: Airport Transfers & Outstation Taxis | ' + BRAND,
        description='Book airport transfers, local rentals and outstation cabs with fixed prices, verified drivers and instant confirmation.',
        h1='Book cabs and airport transfers',
        intro=['Choose the car that suits your group and luggage, see the full price before you book and get driver details before your pickup.'],
        faqs=[('When will I get driver details?', 'Driver and car details are sent to your mobile before the pickup time.')],
    ),
    '/packages': dict(
        title='Holiday Packages: Domestic & International Tour Packages | ' + BRAND,
        description='Explore holiday packages for beaches, hill stations, honeymoons and family trips, with hotels, transfers and sightseeing included. Get exact prices for your group.',
        h1='Holiday packages',
        intro=['Browse ready-made holidays by theme or destination. Each package lists what is included, day-by-day plans and the exact price for your travellers.'],
        faqs=[('Can a package be customised?', 'Yes. Send a request from the package page and our holidays team will tailor hotels, dates and activities for you.')],
    ),
    '/cruises': dict(
        title='Cruise Booking: Ocean & River Cruise Holidays | ' + BRAND,
        description='Compare cruise itineraries, cabins and sailing dates and book with clear, all-inclusive prices.',
        h1='Book cruises', intro=['Compare sailings by route, ship and cabin type, with port fees shown before you pay.'], faqs=[],
    ),
    '/activities': dict(
        title='Things to Do: Tours, Attractions & Experiences | ' + BRAND,
        description='Book tours, attraction tickets and local experiences with instant confirmation and mobile vouchers.',
        h1='Book activities and experiences', intro=['Find tours, theme parks, attraction tickets and local experiences, and get your voucher instantly.'], faqs=[],
    ),
    '/insurance': dict(
        title='Travel Insurance: Domestic & International Cover | ' + BRAND,
        description='Buy travel insurance for medical emergencies, trip delays, baggage loss and cancellations. Compare plans and get your policy instantly.',
        h1='Travel insurance', intro=['Compare plans by cover and price and get your policy document by email straight after payment.'], faqs=[],
    ),
    '/visa': dict(
        title='Visa Services: Tourist & Business Visa Assistance | ' + BRAND,
        description='Apply for tourist and business visas with document checklists, expert help and status updates until your visa is issued.',
        h1='Visa services', intro=['Pick your destination and visa type, add applicants and pay online. Our visa team sends the document checklist and keeps you updated until the visa is issued.'], faqs=[],
    ),
    '/partners': dict(
        title='B2B Travel Portal for Travel Agents, Corporates & Resellers | ' + BRAND,
        description='Join the FyndMyTrip B2B travel portal: agent fares on flights, hotels, buses and holidays, your own markups, credit wallet, white-label booking website, travel API and GST invoices.',
        h1='Grow your travel business with FyndMyTrip',
        intro=[
            'One B2B platform for travel agents, sub-agents, corporates and online resellers. Book flights, hotels, buses, cabs, holidays, insurance and visas for your clients from one login.',
            'Set your own markups, run your own branded booking website, connect through our API and get GST-compliant invoices for every booking.',
        ],
        faqs=[
            ('Who can apply for a B2B account?', 'Registered travel agencies, tour operators, corporates with a travel desk and online travel resellers can apply. Keep your PAN, GSTIN (if registered) and business proof ready.'),
            ('How do I pay for bookings?', 'Agents book from a prepaid or credit wallet, topped up by bank transfer, UPI or card. Every top-up and booking shows in your ledger.'),
            ('Can my clients see my markup?', 'No. Your clients see one fare with your markup included. You can also print tickets without a fare for your clients.'),
            ('Do you offer a white-label website?', 'Yes. Choose from more than 50 website designs, use your own domain and logo, and your customers book directly with you.'),
            ('Is there an API?', 'Yes. Our partner API covers search, booking, ticketing and cancellation, with sandbox keys for testing.'),
        ],
    ),
}


def flight_page(o, d):
    oc, oa = AIRPORTS[o]
    dc, da = AIRPORTS[d]
    intl = o in INTERNATIONAL or d in INTERNATIONAL
    return dict(
        path=flight_path(o, d),
        title=f'{oc} to {dc} Flights: Book Cheap {o} to {d} Flight Tickets | {BRAND}',
        description=f'Compare {oc} to {dc} flight fares across airlines and book {o} to {d} tickets online. Filter by time, stops and refundable fares, choose seats and get an instant e-ticket.',
        h1=f'{oc} to {dc} flights',
        intro=[
            f'Flights from {oc} ({oa}, {o}) to {dc} ({da}, {d}). Search live fares for your date to see every available flight, then filter by departure time, stops, airline and refundable fares.',
            ('This is an international route, so carry a passport valid for at least six months after your travel date and any visa the destination needs.' if intl else 'Domestic flyers need a government photo ID such as Aadhaar, passport, driving licence or voter ID at the airport.'),
        ],
        faqs=[
            (f'Which airport do {oc} to {dc} flights use?', f'Flights leave from {oa} ({o}) and arrive at {da} ({d}).'),
            (f'How do I find the cheapest {oc} to {dc} flight?', 'Search your date, sort by price and look at nearby dates. Saver fares are usually the cheapest; flexible fares cost more but cost less to change.'),
            ('Can I cancel or change my ticket?', "Yes, as the airline's fare rules allow. Each fare shows whether it is refundable before you book, and you can cancel from My Trips."),
            ('When does web check-in open?', f'Most airlines open web check-in {"24" if intl else "48"} hours before departure. The confirmation page links to the airline check-in page.'),
        ],
        params={'from': o, 'to': d}, kind='flight', intl=intl,
    )


def bus_page(o, d):
    return dict(
        path=bus_path(o, d),
        title=f'{o} to {d} Bus Tickets: AC, Sleeper & Volvo Buses | {BRAND}',
        description=f'Book {o} to {d} bus tickets online. Compare operators, timings and fares, choose your seat on the live seat map and get an instant m-ticket.',
        h1=f'{o} to {d} bus tickets',
        intro=[f'Compare buses from {o} to {d} by departure time, bus type, rating and price. Pick your seat and show the m-ticket on your phone when you board.'],
        faqs=[
            (f'What types of buses run from {o} to {d}?', 'Operators run AC and non-AC sleeper and seater buses. Search your date to see the buses available on that day.'),
            ('Do I need to print my ticket?', 'No. Show the m-ticket on your phone along with a photo ID.'),
            ('Can I cancel my bus ticket?', "Yes, according to the operator's cancellation policy shown before you book. Cancel from My Trips."),
        ],
        params={'from': o, 'to': d}, kind='bus',
    )


def hotel_page(city):
    return dict(
        path=hotel_path(city),
        title=f'Hotels in {city}: Book Best Hotels & Resorts in {city} | {BRAND}',
        description=f'Compare and book hotels in {city}, from budget stays to luxury resorts. See room options, free cancellation choices and the total price for your dates.',
        h1=f'Hotels in {city}',
        intro=[f'Find hotels and resorts in {city}. Filter by price, star rating and free cancellation, and see exactly what each room includes before you book.'],
        faqs=[
            (f'How do I find a good hotel in {city}?', 'Search your dates, then sort by price and use the filters for star rating and free cancellation.'),
            ('What are the check-in and check-out times?', 'Most hotels check in from 2 PM and check out by 12 noon. The exact times for your hotel are on your voucher.'),
            ('Can I cancel?', 'Rooms marked free cancellation can be cancelled from My Trips until the deadline shown on the room.'),
        ],
        params={'city': city}, kind='hotel',
    )


def landing_pages():
    return ([flight_page(o, d) for o, d in FLIGHT_ROUTES]
            + [bus_page(o, d) for o, d in BUS_ROUTES]
            + [hotel_page(c) for c in HOTEL_CITIES])
