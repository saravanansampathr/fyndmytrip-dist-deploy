#!/usr/bin/env python3
"""Pre-rendered public pages, sitemap.xml, robots.txt and .htaccess for the
portal (frontend/).

The portal is a single-page app, so every URL used to answer with the same
empty index.html. This writes one real HTML page per public URL into
frontend/seo/ (home, each service, each route landing page, the B2B page):
its own <title>, description, canonical URL, Open Graph tags, JSON-LD
(Organization, WebSite, BreadcrumbList, FAQPage, Service) and the page's
headline, text, FAQs and links inside #root. Search engines and link
previews read that straight away; the app then starts and replaces #root
with the live page. .htaccess serves seo/<path>.html for its exact URL and
falls back to index.html for everything else.

Usage: python3 tools/seo/build_seo.py <frontend_dir> [https://site.url]
(default site: https://demo.fyndmytrip.in). Run after the bundle patches.
"""
import html
import json
import pathlib
import re
import sys
from datetime import date

HERE = pathlib.Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))
import seo_data as D  # noqa: E402

ROOT = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else HERE.parents[1] / 'frontend')
SITE = (sys.argv[2] if len(sys.argv) > 2 else 'https://demo.fyndmytrip.in').rstrip('/')
E = html.escape

SECTION = {'flight': ('/flights', 'Flights'), 'bus': ('/buses', 'Bus tickets'), 'hotel': ('/hotels', 'Hotels')}
SERVICE_LINKS = [('/flights', 'Flights'), ('/hotels', 'Hotels'), ('/buses', 'Bus tickets'), ('/cabs', 'Cabs'),
                 ('/packages', 'Holiday packages'), ('/cruises', 'Cruises'), ('/activities', 'Activities'),
                 ('/insurance', 'Travel insurance'), ('/visa', 'Visa'), ('/partners', 'For travel agents (B2B)')]
PRIVATE = ['/dashboard', '/profile', '/wallet', '/my-bookings', '/my-payments', '/my-account', '/account-security',
           '/admin', '/admin-settings', '/tenants', '/reports', '/accounting', '/support-tickets', '/flights/checkout',
           '/hotels/checkout', '/buses/checkout', '/cabs/checkout', '/cruises/checkout', '/activities/checkout',
           '/insurance/checkout', '/payment-return.html', '/seo/', '/theme-preview', '/reset-password', '/forgot-password']


def all_pages():
    pages = []
    for path, p in D.SERVICE_PAGES.items():
        pages.append(dict(p, path=path, kind=None))
    by = {}
    for p in D.landing_pages():
        by.setdefault(p['kind'], []).append(p)
    for kind, lst in by.items():
        for p in lst:
            p['related'] = [q for q in lst if q is not p][:12]
            pages.append(p)
    # service pages list their landing pages
    for p in pages:
        if p['path'] in ('/flights', '/buses', '/hotels'):
            kind = {'/flights': 'flight', '/buses': 'bus', '/hotels': 'hotel'}[p['path']]
            p['related'] = by.get(kind, [])
    return pages


def ld(page):
    url = SITE + (page['path'] if page['path'] != '/' else '/')
    graph = []
    if page['path'] == '/':
        graph += [
            {'@type': 'TravelAgency', '@id': SITE + '/#org', 'name': D.BRAND, 'url': SITE + '/', 'logo': SITE + '/logo.png',
             'address': {'@type': 'PostalAddress', 'addressLocality': 'Chennai', 'addressRegion': 'Tamil Nadu', 'addressCountry': 'IN'}},
            {'@type': 'WebSite', 'name': D.BRAND, 'url': SITE + '/', 'publisher': {'@id': SITE + '/#org'},
             'potentialAction': {'@type': 'SearchAction', 'target': SITE + '/packages?q={search_term_string}', 'query-input': 'required name=search_term_string'}},
        ]
    crumbs = [('/', 'Home')]
    if page.get('kind') in SECTION:
        crumbs.append(SECTION[page['kind']])
    if page['path'] != '/':
        crumbs.append((page['path'], page['h1']))
    if len(crumbs) > 1:
        graph.append({'@type': 'BreadcrumbList', 'itemListElement': [
            {'@type': 'ListItem', 'position': i + 1, 'name': name, 'item': SITE + path} for i, (path, name) in enumerate(crumbs)]})
    if page.get('faqs'):
        graph.append({'@type': 'FAQPage', 'mainEntity': [
            {'@type': 'Question', 'name': q, 'acceptedAnswer': {'@type': 'Answer', 'text': a}} for q, a in page['faqs']]})
    if page['path'] == '/partners':
        graph.append({'@type': 'Service', 'name': 'FyndMyTrip B2B travel portal', 'serviceType': 'B2B travel booking platform',
                      'provider': {'@type': 'Organization', 'name': D.BRAND, 'url': SITE + '/'}, 'areaServed': 'Worldwide',
                      'audience': {'@type': 'BusinessAudience', 'audienceType': 'Travel agents, tour operators, corporates and online resellers'},
                      'url': url})
    graph.append({'@type': 'WebPage', 'name': page['title'], 'url': url, 'description': page['description'], 'inLanguage': 'en'})
    return json.dumps({'@context': 'https://schema.org', '@graph': graph}, ensure_ascii=False).replace('</', '<\\/')


def body(page):
    out = ['<div class="fmt-pre" data-fmt-prerender>', '<header><a href="/">' + E(D.BRAND) + '</a> <nav aria-label="Services">']
    out += [f'<a href="{p}">{E(l)}</a>' for p, l in SERVICE_LINKS]
    out.append('</nav></header><main>')
    if page.get('kind') in SECTION:
        sp, sl = SECTION[page['kind']]
        out.append(f'<nav aria-label="Breadcrumb"><a href="/">Home</a> › <a href="{sp}">{E(sl)}</a> › <span>{E(page["h1"])}</span></nav>')
    out.append(f'<h1>{E(page["h1"])}</h1>')
    out += [f'<p>{E(t)}</p>' for t in page.get('intro', [])]
    if page['path'] == '/partners':
        out.append('<p><a href="/agent-signup">Apply for a B2B account</a> · <a href="/login">Partner log in</a></p>')
    if page.get('faqs'):
        out.append('<section><h2>Frequently asked questions</h2>')
        for q, a in page['faqs']:
            out.append(f'<h3>{E(q)}</h3><p>{E(a)}</p>')
        out.append('</section>')
    rel = page.get('related') or []
    if rel:
        title = 'Hotels by city' if rel[0]['kind'] == 'hotel' else 'Popular routes'
        out.append(f'<section><h2>{title}</h2><ul>' + ''.join(f'<li><a href="{q["path"]}">{E(q["h1"])}</a></li>' for q in rel) + '</ul></section>')
    out.append('</main><footer><a href="/about-us">About us</a> · <a href="/contact-us">Contact us</a> · <a href="/partners">Partner with us</a> · '
               '<a href="/privacy-policy">Privacy policy</a> · <a href="/terms-and-conditions">Terms &amp; conditions</a> · '
               '<a href="/cancellation-policy">Cancellation policy</a></footer></div>')
    return '\n'.join(out)


def render(tpl, page):
    url = SITE + (page['path'] if page['path'] != '/' else '/')
    t, d = E(page['title']), E(page['description'])
    h = tpl
    h = re.sub(r'<title>.*?</title>', f'<title>{t}</title>', h, flags=re.S)
    h = re.sub(r'<meta\s+name="description"\s+content="[^"]*"\s*/?>', f'<meta name="description" content="{d}" />', h, flags=re.S)
    h = re.sub(r'<meta name="keywords"[^>]*>\s*', '', h)
    h = re.sub(r'<meta property="og:title" content="[^"]*" />', f'<meta property="og:title" content="{t}" />', h)
    h = re.sub(r'<meta\s+property="og:description"\s+content="[^"]*"\s*/>', f'<meta property="og:description" content="{d}" />', h, flags=re.S)
    h = re.sub(r'<meta name="twitter:title" content="[^"]*" />', f'<meta name="twitter:title" content="{t}" />', h)
    h = re.sub(r'<meta\s+name="twitter:description"\s+content="[^"]*"\s*/>', f'<meta name="twitter:description" content="{d}" />', h, flags=re.S)
    h = h.replace('<meta property="og:image" content="/logo.png" />',
                  f'<meta property="og:image" content="{SITE}/logo.png" />\n    <meta property="og:url" content="{url}" />\n'
                  f'    <link rel="canonical" href="{url}" />\n    <meta name="robots" content="index, follow, max-image-preview:large" />\n'
                  f'    <script type="application/ld+json">{ld(page)}</script>')
    assert f'<link rel="canonical" href="{url}"' in h
    h = h.replace('<div id="root"></div>', '<div id="root">\n' + body(page) + '\n</div>')
    assert 'data-fmt-prerender' in h
    return h


ASSETS_HTACCESS = """# Hashed bundle files: safe to cache for a year.
<IfModule mod_headers.c>
Header set Cache-Control "public, max-age=31536000, immutable"
</IfModule>
"""

HTACCESS = r"""# FyndMyTrip portal (generated by tools/seo/build_seo.py). Merge with any
# host-specific rules (for example forcing https) already on the server.
Options -MultiViews
DirectoryIndex index.html
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /
# Pre-rendered public pages (seo/<path>.html) for their exact URL.
RewriteRule ^$ seo/index.html [L]
RewriteCond %{REQUEST_URI} ^/([a-z0-9][a-z0-9/-]*?)/?$
RewriteCond %{DOCUMENT_ROOT}/seo/%1.html -f
RewriteRule ^ seo/%1.html [L]
# Everything else is the single-page app.
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.html [L]
</IfModule>
<IfModule mod_deflate.c>
AddOutputFilterByType DEFLATE text/html text/css application/javascript application/json image/svg+xml text/xml application/xml text/plain
</IfModule>
<IfModule mod_headers.c>
<FilesMatch "\.html$">
Header set Cache-Control "no-cache"
</FilesMatch>
Header always set X-Content-Type-Options "nosniff"
Header always set Referrer-Policy "strict-origin-when-cross-origin"
</IfModule>
"""


def main():
    tpl = (ROOT / 'index.html').read_text(encoding='utf-8')
    seo_dir = ROOT / 'seo'
    if seo_dir.exists():
        for f in sorted(seo_dir.rglob('*.html'), reverse=True):
            f.unlink()
    pages = all_pages()
    urls = []
    for page in pages:
        name = 'index' if page['path'] == '/' else page['path'].strip('/')
        out = seo_dir / f'{name}.html'
        out.parent.mkdir(parents=True, exist_ok=True)
        out.write_text(render(tpl, page), encoding='utf-8')
        urls.append(page['path'])
    for extra in ['/about-us', '/contact-us', '/privacy-policy', '/terms-and-conditions', '/refund-policy',
                  '/cancellation-policy', '/agent-signup']:
        urls.append(extra)
    today = date.today().isoformat()
    sm = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">']
    for u in urls:
        pri = '1.0' if u == '/' else '0.9' if u.count('/') == 1 and u in D.SERVICE_PAGES else '0.7'
        sm.append(f'  <url><loc>{SITE}{u if u != "/" else "/"}</loc><lastmod>{today}</lastmod><priority>{pri}</priority></url>')
    sm.append('</urlset>')
    (ROOT / 'sitemap.xml').write_text('\n'.join(sm) + '\n', encoding='utf-8')
    robots = ['User-agent: *', 'Allow: /'] + [f'Disallow: {p}' for p in PRIVATE] + ['', f'Sitemap: {SITE}/sitemap.xml', '']
    (ROOT / 'robots.txt').write_text('\n'.join(robots), encoding='utf-8')
    (ROOT / '.htaccess').write_text(HTACCESS, encoding='utf-8')
    # Long caching only for the hashed bundle files. Root-level rules would
    # also reach /store/, whose file names do not change between releases.
    (ROOT / 'assets' / '.htaccess').write_text(ASSETS_HTACCESS, encoding='utf-8')
    print(f'SEO: {len(pages)} pre-rendered pages, sitemap with {len(urls)} URLs, robots.txt, .htaccess ({SITE})')


if __name__ == '__main__':
    main()
