// Admin-managed extras on the storefront, with no change to the booking
// pages: home banners, offers and FAQ (Admin settings → Banners, offers &
// FAQ), the page title and description for Google (SEO titles), and the
// partner's Google Analytics / Tag Manager / Ads / Meta Pixel tags
// (Admin settings → Analytics & ads). Only IDs come from the API.
(function () {
  var cfg = window.FMT_CONFIG || {};
  var API = (cfg.apiBase || 'https://api-demo.fyndmytrip.in/api').replace(/\/$/, '');
  var HDR = { Accept: 'application/json', 'X-Storefront-Host': cfg.storefrontHost || location.host };
  function get(path) { return fetch(API + path, { headers: HDR }).then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); }); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function safeUrl(u) { u = String(u || ''); return /^(https?:\/\/|\/|#)/.test(u) ? u : ''; }
  function addScript(src, text) { var s = document.createElement('script'); if (src) { s.async = true; s.src = src; } else { s.text = text; } document.head.appendChild(s); }
  var tags = {};
  var css = document.createElement('style');
  css.textContent = '.fmt-banners{display:grid;grid-auto-flow:column;grid-auto-columns:minmax(280px,1fr);gap:14px;overflow-x:auto}' +
    '.fmt-banner{display:block;border-radius:12px;overflow:hidden;background:var(--surface-2,#f1f5f9);color:inherit;text-decoration:none;min-height:120px}' +
    '.fmt-banner img{width:100%;height:150px;object-fit:cover;display:block}.fmt-banner div{padding:12px}.fmt-banner b{display:block;margin-bottom:4px}' +
    '.fmt-faq details{border-bottom:1px solid var(--line,#e2e8f0);padding:10px 0}.fmt-faq summary{cursor:pointer;font-weight:600}';
  document.head.appendChild(css);

  // ---- analytics and ads tags (IDs validated by the API)
  function loadTags(t) {
    tags = t || {};
    var gid = tags.ga4_id || tags.google_ads_id;
    if (gid && /^(G|AW)-[A-Z0-9-]+$/.test(gid)) {
      addScript('https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(gid));
      window.dataLayer = window.dataLayer || [];
      window.gtag = function () { window.dataLayer.push(arguments); };
      window.gtag('js', new Date());
      if (tags.ga4_id) window.gtag('config', tags.ga4_id);
      if (tags.google_ads_id) window.gtag('config', tags.google_ads_id);
    }
    if (tags.gtm_id && /^GTM-[A-Z0-9]+$/.test(tags.gtm_id)) {
      window.dataLayer = window.dataLayer || [];
      window.dataLayer.push({ 'gtm.start': Date.now(), event: 'gtm.js' });
      addScript('https://www.googletagmanager.com/gtm.js?id=' + encodeURIComponent(tags.gtm_id));
    }
    if (tags.meta_pixel_id && /^\d+$/.test(tags.meta_pixel_id)) {
      addScript(null, "!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','" + tags.meta_pixel_id + "');fbq('track','PageView');");
    }
  }
  var lastTracked = '';
  function trackPage(parts) {
    var path = '/' + parts.join('/');
    if (window.gtag && tags.ga4_id) window.gtag('event', 'page_view', { page_path: path });
    if (window.fbq) window.fbq('track', 'PageView');
    // The confirmation page (#/done/<service>/<booking>) is a purchase.
    if (parts[0] === 'done' && parts[2] && lastTracked !== parts[2]) {
      // Only once the page shows the booking (not "Booking not found").
      var tries = 0;
      (function wait() { if (document.querySelector('#page .done .done-ic')) purchase(parts); else if (tries++ < 50) setTimeout(wait, 200); })();
    }
  }
  function purchase(parts) {
    if (lastTracked === parts[2]) return;
    {
      lastTracked = parts[2];
      var info = { transaction_id: String(parts[2]), items: [{ item_category: parts[1] }] };
      if (window.gtag && tags.ga4_id) window.gtag('event', 'purchase', info);
      if (window.gtag && tags.google_ads_id && tags.google_ads_purchase_label) window.gtag('event', 'conversion', { send_to: tags.google_ads_id + '/' + tags.google_ads_purchase_label, transaction_id: String(parts[2]) });
      if (window.fbq) window.fbq('track', 'Purchase', { content_category: parts[1], currency: 'INR', value: 0 });
      if (window.dataLayer) window.dataLayer.push({ event: 'purchase', transaction_id: String(parts[2]), service: parts[1] });
    }
  }

  // ---- SEO title / description per page
  var baseTitle = '', lastSet = '';
  function setMeta(title, desc) {
    if (title) {
      if (document.title !== lastSet) baseTitle = document.title;
      document.title = lastSet = title;
    } else if (lastSet && document.title === lastSet) {
      document.title = baseTitle; lastSet = '';
    }
    var m = document.querySelector('meta[name="description"]');
    if (!m) { m = document.createElement('meta'); m.name = 'description'; document.head.appendChild(m); }
    if (desc || m.content) m.content = desc || '';
  }
  function seo(path) {
    get('/content?type=seo&path=' + encodeURIComponent(path)).then(function (r) {
      var it = (r.data && r.data.seo && r.data.seo[0]) || null;
      setMeta(it && it.data && it.data.meta_title, it && it.data && it.data.meta_description);
    }).catch(function () {});
  }

  // ---- home: banners, offers, FAQ
  function home(svc) {
    var root = document.getElementById('page');
    if (!root || root.querySelector('[data-fmt-extras]')) return;
    var mark = document.createElement('div'); mark.setAttribute('data-fmt-extras', ''); mark.hidden = true; root.appendChild(mark);
    get('/content?type=banner,offer,faq&module=' + encodeURIComponent(svc || '')).then(function (r) {
      var d = r.data || {};
      var hero = root.querySelector('.hero');
      if ((d.banner || []).length && hero) {
        var s = document.createElement('section'); s.className = 'sec'; s.setAttribute('data-fmt-banners', '');
        s.innerHTML = '<div class="wrap"><div class="fmt-banners">' + d.banner.map(function (b) {
          var inner = (b.image_url ? '<img src="' + esc(safeUrl(b.image_url)) + '" alt="' + esc(b.title) + '" loading="lazy">' : '') + '<div><b>' + esc(b.title) + '</b>' + (b.body || '') + '</div>';
          var href = safeUrl(b.link_url);
          return href ? '<a class="fmt-banner" href="' + esc(href) + '">' + inner + '</a>' : '<div class="fmt-banner">' + inner + '</div>';
        }).join('') + '</div></div>';
        hero.insertAdjacentElement('afterend', s);
      }
      var offersSec = root.querySelector('#offers') && root.querySelector('#offers').closest('section');
      if ((d.offer || []).length && offersSec) {
        var o2 = document.createElement('section'); o2.className = 'sec'; o2.setAttribute('data-fmt-offers', '');
        o2.innerHTML = '<div class="wrap"><h2>Special offers</h2><div class="offer-row">' + d.offer.map(function (o) {
          var code = o.data && o.data.coupon_code ? '<p class="small">Use code <b>' + esc(o.data.coupon_code) + '</b></p>' : '';
          var inner = (o.image_url ? '<img src="' + esc(safeUrl(o.image_url)) + '" alt="" loading="lazy" style="width:100%;border-radius:8px">' : '') + '<b>' + esc(o.title) + '</b>' + (o.body || '') + code;
          var href = safeUrl(o.link_url);
          return href ? '<a class="offer" href="' + esc(href) + '">' + inner + '</a>' : '<div class="offer">' + inner + '</div>';
        }).join('') + '</div></div>';
        offersSec.insertAdjacentElement('beforebegin', o2);
      }
      if ((d.faq || []).length) {
        var f = document.createElement('section'); f.className = 'sec fmt-faq'; f.setAttribute('data-fmt-faq', '');
        f.innerHTML = '<div class="wrap"><h2>Frequently asked questions</h2>' + d.faq.map(function (q) { return '<details><summary>' + esc(q.title) + '</summary><div>' + (q.body || '') + '</div></details>'; }).join('') + '</div>';
        root.appendChild(f);
      }
    }).catch(function () {});
  }

  function onRoute() {
    var h = (location.hash || '#/').replace(/^#\/?/, '');
    var path = h.split('?')[0];
    var parts = path ? path.split('/') : [];
    var svc = new URLSearchParams(h.split('?')[1] || '').get('s') || (parts[1] || 'flight');
    trackPage(parts);
    seo(parts.length ? '/' + parts[0] + (parts[1] ? '/' + parts[1] : '') : '/' + (svc === 'flight' ? '' : svc));
    if (!parts.length || parts[0] === '') {
      // The home page renders after this; wait for it.
      var tries = 0;
      (function wait() { var p = document.getElementById('page'); if (p && p.querySelector('.hero')) home(svc); else if (tries++ < 40) setTimeout(wait, 100); })();
    }
  }
  get('/whitelabel/current').then(function (r) { loadTags(r.data && r.data.tracking); }).catch(function () {});
  window.addEventListener('hashchange', function () { setTimeout(onRoute, 50); });
  window.addEventListener('load', function () { setTimeout(onRoute, 300); });
})();
