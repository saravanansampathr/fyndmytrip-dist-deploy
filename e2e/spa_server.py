import http.server, os, sys
ROOT = sys.argv[1]; PORT = int(sys.argv[2])
# Mirrors the portal's .htaccess: a pre-rendered page in seo/ wins for its
# exact path ("/" -> seo/index.html), then real files, then index.html.
class H(http.server.SimpleHTTPRequestHandler):
    def __init__(self, *a, **k): super().__init__(*a, directory=ROOT, **k)
    def send_head(self):
        path = self.path.split('?')[0]
        rel = path.strip('/')
        seo = os.path.join(ROOT, 'seo', (rel or 'index') + '.html')
        if rel != 'seo' and not rel.startswith('seo/') and os.path.isfile(seo) and not os.path.isfile(os.path.join(ROOT, rel)):
            self.path = '/seo/' + (rel or 'index') + '.html'
        elif not os.path.exists(self.translate_path(path)): self.path = '/index.html'
        return super().send_head()
    def log_message(self, *a): pass
http.server.ThreadingHTTPServer(('127.0.0.1', PORT), H).serve_forever()
