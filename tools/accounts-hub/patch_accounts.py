#!/usr/bin/env python3
"""Accounts & inventory screen for the compiled portal (frontend/).

Run by tools/build_portal.sh after the other patches (it uses their helper
functions). Adds accounts.src.js, the /accounts route and a menu entry
right under Tenants for super admins, platform staff and white-label
admins. Usage: python3 tools/accounts-hub/patch_accounts.py <frontend_dir>
"""
import hashlib
import pathlib
import re
import sys

HERE = pathlib.Path(__file__).resolve().parent
ROOT = pathlib.Path(sys.argv[1])


def sub_once(s, old, new):
    assert s.count(old) == 1, old[:80]
    return s.replace(old, new, 1)


def main():
    assets = ROOT / 'assets'
    html_p = ROOT / 'index.html'
    html = html_p.read_text(encoding='utf-8')
    main_name = re.search(r'/assets/(index-[A-Za-z0-9_-]+\.js)', html).group(1)
    s = (assets / main_name).read_text(encoding='utf-8')

    s = sub_once(s, 'function Use(){', (HERE / 'accounts.src.js').read_text(encoding='utf-8').strip() + '\nfunction Use(){')
    s = sub_once(s, "(0,U.jsx)(H,{path:`/my-account`,element:(0,U.jsx)(FmtMyAccountPage,{})})",
                 "(0,U.jsx)(H,{path:`/my-account`,element:(0,U.jsx)(FmtMyAccountPage,{})}),(0,U.jsx)(H,{path:`/accounts`,element:(0,U.jsx)(FmtAccountsPage,{})})")
    s = sub_once(s, "{to:`/tenants`,label:`Tenants`,icon:`⌂`,roles:[`super_admin`,`staff`,`partner_admin`,`agent`]}",
                 "{to:`/tenants`,label:`Tenants`,icon:`⌂`,roles:[`super_admin`,`staff`,`partner_admin`,`agent`]},{to:`/accounts`,label:`Accounts & inventory`,icon:`🧩`,roles:[`super_admin`,`staff`,`partner_admin`]}")

    new_main = f'index-{hashlib.sha256(s.encode()).hexdigest()[:8]}.js'
    for chunk in sorted(assets.glob('*.js')):
        if chunk.name == main_name:
            continue
        c = chunk.read_text(encoding='utf-8')
        if main_name in c:
            c = c.replace(main_name, new_main)
            base = chunk.name.rsplit('-', 1)[0]
            nn = f'{base}-{hashlib.sha256(c.encode()).hexdigest()[:8]}.js'
            (assets / nn).write_text(c, encoding='utf-8')
            chunk.unlink()
            s = s.replace(chunk.name, nn)
    (assets / main_name).unlink()
    (assets / new_main).write_text(s, encoding='utf-8')
    html_p.write_text(html.replace(main_name, new_main), encoding='utf-8')
    print('accounts patch applied:', new_main)


if __name__ == '__main__':
    main()
