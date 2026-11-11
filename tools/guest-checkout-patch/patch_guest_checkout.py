#!/usr/bin/env python3
"""Portal (compiled frontend/) changes for guest checkout. Safe to re-run.

The React source isn't in this repo, so like tools/frontend-margin-patch
this edits the built bundle in place:

1. A guest checkout response is no longer treated as a login: the guest
   session token is kept (so the guest can add extras and pay for that one
   booking) but no user is stored, so the portal never shows the guest as
   signed in under the email they typed.
2. The checkout copy no longer says an account will be created.

Usage: python3 tools/guest-checkout-patch/patch_guest_checkout.py [frontend_dir]
"""
import pathlib
import sys

ROOT = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else pathlib.Path(__file__).resolve().parents[2] / 'frontend')

REPLACEMENTS = [
    (
        'credentialsReceived(e,t){e.user=t.payload.user,e.token=t.payload.token,localStorage.setItem(Fs,t.payload.token),localStorage.setItem(Is,JSON.stringify(t.payload.user))}',
        'credentialsReceived(e,t){let g=!!(t.payload.user&&t.payload.user.is_guest);e.user=g?null:t.payload.user,e.token=t.payload.token,localStorage.setItem(Fs,t.payload.token),g?localStorage.removeItem(Is):localStorage.setItem(Is,JSON.stringify(t.payload.user))}',
    ),
    (
        "Booking as a guest — we'll set up an account for you with the details below so you can track this booking anytime.",
        "Booking as a guest — no account or password needed. We'll send your booking to the email and mobile below.",
    ),
    (
        "Booking as a guest — we'll create your account automatically so you can track this booking later.",
        "Booking as a guest — no account or password needed. We'll send your booking to the email and mobile below.",
    ),
    (
        "held successfully. We've set up an account for you using ${c} so you can track this booking anytime — use \"Forgot password\" on the login page whenever you'd like to set one.",
        "held successfully. We'll send your booking details to ${c}. No account is needed; sign up anytime if you'd like one.",
    ),
    (
        "You may book as a registered user or as a guest; a guest checkout automatically creates an account for you using the details you provide, so you can view and manage that booking later.",
        "You may book as a registered user or as a guest. A guest booking does not create an account; booking details are sent to the email and mobile you provide.",
    ),
]


def main():
    bundles = [p for p in (ROOT / 'assets').glob('index-*.js')]
    changed = 0
    for path in bundles:
        text = path.read_text(encoding='utf-8')
        original = text
        for old, new in REPLACEMENTS:
            if new in text:
                continue
            count = text.count(old)
            if count:
                text = text.replace(old, new)
        if text != original:
            path.write_text(text, encoding='utf-8')
            changed += 1
    missing = [old[:60] for old, new in REPLACEMENTS if not any(new in p.read_text(encoding='utf-8') for p in bundles)]
    if missing:
        sys.exit('guest checkout patch: not found in bundle: ' + ' | '.join(missing))
    print(f'guest checkout patch applied ({changed} file(s) changed)')


if __name__ == '__main__':
    main()
