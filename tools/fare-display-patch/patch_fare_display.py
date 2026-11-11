#!/usr/bin/env python3
"""Portal (compiled frontend/) changes for how fares appear on tickets. Safe to re-run.

1. "E-ticket (no fare shown)" (booking confirmation) and "Ticket (no fare)"
   (booking details) only appear when the API says the viewer may hide the
   fare (BookingResource.can_hide_fare): B2B bookings, agent side only.
   Direct customers only get the ticket with the price.
2. The terms no longer promise that markups are shown separately; the
   traveller sees one all-in price.

Usage: python3 tools/fare-display-patch/patch_fare_display.py [frontend_dir]
"""
import pathlib
import sys

ROOT = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else pathlib.Path(__file__).resolve().parents[2] / 'frontend')

REPLACEMENTS = [
    (
        '(0,U.jsx)(J,{variant:`secondary`,disabled:_===`without`,onClick:()=>S(!1),children:_===`without`?`Opening…`:`E-ticket (no fare shown)`})',
        'l.can_hide_fare&&(0,U.jsx)(J,{variant:`secondary`,disabled:_===`without`,onClick:()=>S(!1),children:_===`without`?`Opening…`:`E-ticket (no fare shown)`})',
    ),
    (
        '(0,U.jsx)(J,{variant:`secondary`,onClick:()=>qm(e.id,!1),children:`Ticket (no fare)`})',
        'e.can_hide_fare&&(0,U.jsx)(J,{variant:`secondary`,onClick:()=>qm(e.id,!1),children:`Ticket (no fare)`})',
    ),
    (
        'Markups, taxes, and convenience fees (where applicable) are shown before you confirm a booking.',
        'The total price, including applicable taxes, is shown before you confirm a booking.',
    ),
]


def main():
    bundles = [p for p in (ROOT / 'assets').glob('index-*.js')]
    changed = 0
    for bundle in bundles:
        s = bundle.read_text(encoding='utf-8')
        orig = s
        for old, new in REPLACEMENTS:
            if new in s:
                continue
            if old not in s:
                continue
            s = s.replace(old, new)
        if s != orig:
            bundle.write_text(s, encoding='utf-8')
            changed += 1
    text = ''.join(b.read_text(encoding='utf-8') for b in bundles)
    missing = [new[:60] for _, new in REPLACEMENTS if new not in text]
    if missing:
        sys.exit('Patch did not apply: ' + '; '.join(missing))
    print(f'fare display patch applied ({changed} bundle(s) changed)')


if __name__ == '__main__':
    main()
