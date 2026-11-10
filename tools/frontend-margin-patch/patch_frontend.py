"""Rebuilds frontend/ with the margin-rules page swapped in.

The React/TypeScript source of the portal is not in this repo (only the
compiled Vite build is), so the Margin rules page is written directly in
the bundle's own style (markup_page.src.js) and spliced over the old
page's functions. Usage:

    python3 patch_frontend.py <original build dir> <output dir>

<original build dir> is an unpatched batch-106 build (index.html +
assets/index-ZxkLBm7F.js, as in updatedassets.zip or this repo's first
import commit).
"""
import hashlib, os, re, shutil, sys
SRC, OUT = sys.argv[1], sys.argv[2]
HERE = os.path.dirname(os.path.abspath(__file__))
js_name, css_name = 'index-ZxkLBm7F.js', 'index-FKMQ4pEu.css'
s = open(f'{SRC}/assets/{js_name}', encoding='utf-8').read()
i = s.index('async function uae(){'); j = s.index('}async function xae(){') + 1
new_page = open(os.path.join(HERE, 'markup_page.src.js'), encoding='utf-8').read().strip()
s = s[:i] + new_page + s[j:]
old_nav = "{to:`/markup-rules`,label:`Markup rules`,icon:`⇧`,roles:[`super_admin`,`staff`,`partner_admin`,`agent`,`sub_agent`]}"
assert s.count(old_nav) == 1
s = s.replace(old_nav, "{to:`/markup-rules`,label:`Margin rules`,icon:`⇧`,roles:[`super_admin`,`staff`,`partner_admin`,`agent`,`sub_agent`,`api_user`]}")
h = hashlib.sha256(s.encode()).hexdigest()[:8]
new_js = f'index-{h}.js'
if os.path.exists(OUT): shutil.rmtree(OUT)
os.makedirs(f'{OUT}/assets')
html = open(f'{SRC}/index.html', encoding='utf-8').read()
refs = set(re.findall(r'/assets/([^"\']+)', html))
assert js_name in refs and css_name in refs, refs
html = html.replace(js_name, new_js)
open(f'{OUT}/index.html', 'w', encoding='utf-8').write(html)
# Lazy chunks import the main bundle by file name, so give them the new
# name too (and new names of their own, so no stale cached copy is reused).
renamed = {}
for f in sorted(set(re.findall(r'`\./([A-Za-z0-9_.\-]+\.js)`', s))):
    c = open(f'{SRC}/assets/{f}', encoding='utf-8').read()
    if js_name in c:
        c = c.replace(js_name, new_js)
        base = f.rsplit('-', 1)[0]
        nf = f'{base}-{hashlib.sha256(c.encode()).hexdigest()[:8]}.js'
        open(f'{OUT}/assets/{nf}', 'w', encoding='utf-8').write(c)
        renamed[f] = nf
for old, nf in renamed.items():
    s = s.replace(f'`./{old}`', f'`./{nf}`')
assert js_name not in s
open(f'{OUT}/assets/{new_js}', 'w', encoding='utf-8').write(s)
# lazily imported chunks referenced from the main bundle
chunks = set(re.findall(r'assets/([A-Za-z0-9_.\-]+\.(?:js|css))', s)) | set(re.findall(r'"\./([A-Za-z0-9_.\-]+\.js)"', s)) | set(re.findall(r'`\./([A-Za-z0-9_.\-]+\.js)`', s))
for f in sorted(refs | chunks):
    if f == js_name or f in renamed: continue
    p = f'{SRC}/assets/{f}'
    if os.path.exists(p): shutil.copy(p, f'{OUT}/assets/{f}')
    elif f not in renamed.values(): print('missing chunk', f)
for f in ['favicon.png', 'apple-touch-icon.png', 'logo.png', 'logo-light.png', 'icons.svg']:
    shutil.copy(f'{SRC}/{f}', f'{OUT}/{f}')
for d in ['data', 'payments', 'certifications']:
    shutil.copytree(f'{SRC}/{d}', f'{OUT}/{d}')
print('built', new_js, sorted(os.listdir(f'{OUT}/assets')))
