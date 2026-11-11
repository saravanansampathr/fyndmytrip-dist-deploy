/* fyndmytrip booking UX layer. Spliced into the compiled portal bundle by
   patch_booking_ux.py, so it is written against the bundle's own names:
   U = react/jsx-runtime, x = React, ft = useLocation, ht = useNavigate,
   Xs = useSelector, X = Card, J = Button, Mn = Link, kY = useDocumentTitle,
   Pf = useQuery for /whitelabel/current, Zf = currency formatter hook,
   K = axios client. FMT_SEO (route data from tools/seo/seo_data.py) is
   defined just before this file by the patch script. */
function fmtH(t,p,...c){let k=c.flat(9).filter(v=>v!=null&&v!==!1&&v!==``);return(0,U.jsx)(t,{...(p||{}),children:k.length===0?void 0:k.length===1?k[0]:k.map((v,i)=>typeof v===`object`&&v&&!v.key?(0,U.jsx)(U.Fragment,{children:v},i):v)})}
function fmtBrandInfo(){let q=Pf(),w=q.data||{};return{name:String(w.name||`FyndMyTrip`).replace(/\s*\((master|default)\)\s*$/i,``),email:w.support_email||null,phone:w.support_phone||null}}
function fmtMask(e){if(!e)return``;let[a,b]=String(e).split(`@`);return b?a.slice(0,2)+`•••@`+b:e}
function fmtMaskPhone(p){let d=String(p||``).replace(/\D/g,``);return d.length>4?`•••••`+d.slice(-4):p||``}
var FMT_SVC={
flight:{noun:`flight`,doc:`e-ticket`,next:[[`✉️`,`Your e-ticket is on its way`,`We have emailed your e-ticket and sent the booking details to your mobile. You can also download it below at any time.`],[`🛫`,`Check in online`,`Most airlines open web check-in 48 hours before a domestic flight and 24 hours before an international one. Use the airline link on your itinerary.`],[`🪪`,`Carry a photo ID`,`Take a government photo ID (a passport for international travel) and reach the airport at least 2 hours before departure, or 3 hours for international flights.`]]},
hotel:{noun:`stay`,doc:`voucher`,next:[[`✉️`,`Your hotel voucher is on its way`,`We have emailed your voucher. You can also download it below.`],[`🪪`,`At check-in`,`Show the voucher on your phone or printed, with a government photo ID for every adult guest.`],[`🕑`,`Check-in times`,`Most hotels check in from 2 PM and check out by 12 noon. Early check-in and other requests depend on availability at the hotel.`]]},
bus:{noun:`trip`,doc:`m-ticket`,next:[[`📱`,`Your m-ticket is on its way`,`We have emailed your ticket and sent it to your mobile. Show it on your phone when you board.`],[`📍`,`Reach your boarding point early`,`Be at the boarding point at least 15 minutes before departure. The operator's contact number is on your ticket.`],[`🪪`,`Carry a photo ID`,`Keep a government photo ID handy for every passenger.`]]},
cab:{noun:`ride`,doc:`voucher`,next:[[`✉️`,`Booking details sent`,`Your voucher is in your email.`],[`🚗`,`Driver details`,`The driver's name, number and car details are sent to your mobile before pickup.`],[`⏱️`,`Be ready on time`,`Please be at the pickup point on time. Waiting time beyond the free limit may be charged by the operator.`]]},
cruise:{noun:`sailing`,doc:`voucher`,next:[[`✉️`,`Your cruise voucher is on its way`,`We have emailed your voucher.`],[`🛂`,`Documents`,`Carry the photo ID or passport used for booking, and any visas the itinerary needs.`],[`⚓`,`Boarding`,`Reach the port at the reporting time printed on your voucher.`]]},
activity:{noun:`experience`,doc:`voucher`,next:[[`✉️`,`Your voucher is on its way`,`We have emailed your voucher. Show it on your phone at the venue.`],[`⏰`,`Arrive a little early`,`Reach the meeting point or venue 15 minutes before the start time.`]]},
insurance:{noun:`cover`,doc:`policy`,next:[[`✉️`,`Your policy document is on its way`,`We have emailed your policy. Keep it handy while you travel.`],[`☎️`,`Need to claim?`,`Call the insurer's helpline printed on the policy as soon as something happens, and keep bills and reports.`]]},
};
function fmtIcs(b,svc,when,title){let d=(when||b.travel_date_start||``).replace(/[-:]/g,``).slice(0,15);if(!d)return null;let allDay=d.length<=8,end=b.travel_date_end&&b.travel_date_end!==b.travel_date_start?b.travel_date_end.replace(/-/g,``):null,
lines=[`BEGIN:VCALENDAR`,`VERSION:2.0`,`PRODID:-//FyndMyTrip//Booking//EN`,`BEGIN:VEVENT`,`UID:${b.booking_code||b.id}@fyndmytrip`,`DTSTAMP:${new Date().toISOString().replace(/[-:]/g,``).slice(0,15)}Z`,allDay?`DTSTART;VALUE=DATE:${d.slice(0,8)}`:`DTSTART:${d}`,end?`DTEND;VALUE=DATE:${end}`:``,`SUMMARY:${title}`,`DESCRIPTION:Booking ID ${b.booking_code||b.booking_reference}${b.supplier_booking_ref?` · PNR `+b.supplier_booking_ref:``}`,`END:VEVENT`,`END:VCALENDAR`].filter(Boolean);
return URL.createObjectURL(new Blob([lines.join(`\r\n`)],{type:`text/calendar`}))}
function fmtTripTitle(b,svc,route){let n=FMT_SVC[svc]?.noun||`trip`;return route?`${route} ${n}`:`Your ${n}`}
function FmtConfirmHero({booking:b,service:svc=`flight`,route:route,when:when}){
let br=fmtBrandInfo(),[copied,setCopied]=(0,x.useState)(!1),guest=fmtGuest(),code=b.booking_code||b.booking_reference,cfg=FMT_SVC[svc]||FMT_SVC.flight,
title=fmtTripTitle(b,svc,route),ics=(0,x.useMemo)(()=>fmtIcs(b,svc,when,`${title} (${code})`),[b.id]),
share=`My ${cfg.noun} is booked with ${br.name}. Booking ID ${code}${route?` · `+route:``}${b.travel_date_start?` · `+b.travel_date_start:``}`;
function copy(){try{navigator.clipboard.writeText(code),setCopied(!0),setTimeout(()=>setCopied(!1),1800)}catch(_){}}
return fmtH(`div`,{className:`fmt-hero`,"data-testid":`fmt-confirm-hero`},
 fmtH(`div`,{className:`fmt-hero-top`},
  fmtH(`div`,{className:`fmt-hero-badge`,"aria-hidden":`true`},`✓`),
  fmtH(`div`,{className:`fmt-hero-text`},
   fmtH(`h2`,{className:`fmt-hero-title`},svc===`insurance`?`Policy confirmed`:`Booking confirmed`),
   fmtH(`p`,{className:`fmt-hero-sub`},route?[`Your ${cfg.noun} `,fmtH(`strong`,{},route),` is booked. `]:`Thank you for booking with ${br.name}. `,b.contact_email?[`Your ${cfg.doc} has been sent to `,fmtH(`strong`,{},fmtMask(b.contact_email)),b.contact_phone?[` and `,fmtH(`strong`,{},fmtMaskPhone(b.contact_phone))]:null,`.`]:`Your ${cfg.doc} is ready to download below.`))),
 fmtH(`div`,{className:`fmt-hero-ids`},
  fmtH(`div`,{className:`fmt-id`},fmtH(`span`,{className:`fmt-id-l`},`Booking ID`),fmtH(`span`,{className:`fmt-id-v`,"data-testid":`fmt-booking-id`},code),fmtH(`button`,{type:`button`,className:`fmt-copy`,onClick:copy,"aria-label":`Copy booking ID`},copied?`Copied`:`Copy`)),
  b.supplier_booking_ref&&fmtH(`div`,{className:`fmt-id`},fmtH(`span`,{className:`fmt-id-l`},svc===`flight`?`Airline PNR`:svc===`insurance`?`Policy no.`:`Confirmation no.`),fmtH(`span`,{className:`fmt-id-v`},b.supplier_booking_ref)),
  fmtH(`div`,{className:`fmt-id`},fmtH(`span`,{className:`fmt-id-l`},`Status`),fmtH(`span`,{className:`fmt-chip-ok`},String(b.status||`confirmed`).replace(/_/g,` `)))),
 fmtH(`div`,{className:`fmt-hero-actions`},
  ics&&fmtH(`a`,{href:ics,download:`${code}.ics`,className:`fmt-act`},`📅 Add to calendar`),
  fmtH(`a`,{href:`https://wa.me/?text=${encodeURIComponent(share)}`,target:`_blank`,rel:`noopener noreferrer`,className:`fmt-act`},`💬 Share on WhatsApp`),
  fmtH(`button`,{type:`button`,className:`fmt-act`,onClick:()=>window.print()},`🖨️ Print`),
  !guest&&fmtH(Mn,{to:`/my-bookings`,className:`fmt-act`},`🧳 My trips`)))}
function FmtNextSteps({booking:b,service:svc=`flight`}){
let br=fmtBrandInfo(),cfg=FMT_SVC[svc]||FMT_SVC.flight,guest=fmtGuest(),code=b.booking_code||b.booking_reference;
return fmtH(`div`,{className:`fmt-next`,"data-testid":`fmt-next-steps`},
 fmtH(`div`,{className:`fmt-next-main`},
  fmtH(`h3`,{className:`fmt-h3`},`What happens next`),
  fmtH(`ol`,{className:`fmt-steps`},cfg.next.map(([ic,t,d])=>fmtH(`li`,{key:t},fmtH(`span`,{className:`fmt-step-ic`,"aria-hidden":`true`},ic),fmtH(`div`,{},fmtH(`strong`,{},t),fmtH(`p`,{},d)))))),
 fmtH(`div`,{className:`fmt-next-side`},
  fmtH(`div`,{className:`fmt-help`},
   fmtH(`h3`,{className:`fmt-h3`},`Need help with this booking?`),
   fmtH(`p`,{},`Quote booking ID `,fmtH(`strong`,{},code),` when you contact us.`),
   br.phone&&fmtH(`a`,{className:`fmt-help-l`,href:`tel:${String(br.phone).replace(/\s/g,``)}`},`📞 `,br.phone),
   br.email&&fmtH(`a`,{className:`fmt-help-l`,href:`mailto:${br.email}?subject=${encodeURIComponent(`Booking `+code)}`},`✉️ `,br.email),
   fmtH(Mn,{className:`fmt-help-l`,to:guest?`/contact-us`:`/support-tickets`},`🎫 `,guest?`Contact us`:`Raise a support ticket`)),
  fmtH(`div`,{className:`fmt-help`},
   fmtH(`h3`,{className:`fmt-h3`},`Changes and cancellations`),
   fmtH(`p`,{},guest?`To change or cancel, contact us with your booking ID and the email you booked with. Create an account with the same email to manage future trips yourself.`:`You can cancel from My Trips, and see the refund before you confirm.`),
   fmtH(Mn,{className:`fmt-help-l`,to:`/cancellation-policy`},`Read the cancellation policy →`))))}
function FmtConsent(){return fmtH(`p`,{className:`fmt-consent`,"data-testid":`fmt-consent`},`By continuing, you agree to the fare rules, our `,fmtH(Mn,{to:`/terms-and-conditions`,target:`_blank`},`Terms & conditions`),`, `,fmtH(Mn,{to:`/privacy-policy`,target:`_blank`},`Privacy policy`),` and `,fmtH(Mn,{to:`/cancellation-policy`,target:`_blank`},`Cancellation policy`),`.`)}
function FmtPayTrust(){return fmtH(`ul`,{className:`fmt-paytrust`,"data-testid":`fmt-pay-trust`},fmtH(`li`,{},`🔒 Encrypted, secure payment`),fmtH(`li`,{},`🛡️ Card details are handled by the payment gateway, never stored by us`),fmtH(`li`,{},`🧾 GST invoice with every booking`))}
function FmtSummaryTrust(){let br=fmtBrandInfo();return fmtH(`div`,{className:`fmt-sumtrust`,"data-testid":`fmt-summary-trust`},
 fmtH(`div`,{className:`fmt-st`},fmtH(`span`,{"aria-hidden":`true`},`✔`),`Price includes all taxes and fees`),
 fmtH(`div`,{className:`fmt-st`},fmtH(`span`,{"aria-hidden":`true`},`🔒`),`Secure payment by UPI, cards, netbanking or wallet`),
 fmtH(`div`,{className:`fmt-st`},fmtH(`span`,{"aria-hidden":`true`},`⚡`),`Instant confirmation on email and mobile`),
 (br.phone||br.email)&&fmtH(`div`,{className:`fmt-st`},fmtH(`span`,{"aria-hidden":`true`},`🎧`),`Help: `,br.phone||br.email))}

/* ---------- SEO landing pages: /flights/delhi-to-mumbai-flights etc. ---------- */
function fmtLandNow(){try{let p=location.pathname.replace(/\/+$/,``),g=FMT_SEO.pages[p];return g&&g.kind?g:null}catch(_){return null}}
function FmtLanding({base:Base}){let loc=ft(),p=loc.pathname.replace(/\/+$/,``),pg=FMT_SEO.pages[p];
kY(pg?pg.title:document.title,pg?pg.description:void 0);
if(!pg)return(0,U.jsx)(Base,{});
return fmtH(U.Fragment,{},(0,U.jsx)(Base,{},p),(0,U.jsx)(FmtSeoBlock,{page:pg,path:p}))}
function FmtSeoBlock({page:pg,path:p}){
let rel=(pg.related||[]).map(r=>FMT_SEO.pages[r]?{to:r,label:FMT_SEO.pages[r].h1}:null).filter(Boolean),
crumbs=[[`/`,`Home`],[pg.kind===`flight`?`/flights`:pg.kind===`bus`?`/buses`:`/hotels`,pg.kind===`flight`?`Flights`:pg.kind===`bus`?`Bus tickets`:`Hotels`],[p,pg.h1]];
return fmtH(`section`,{className:`fmt-seo`,"data-testid":`fmt-seo-block`},
 fmtH(`nav`,{"aria-label":`Breadcrumb`,className:`fmt-crumbs`},crumbs.map(([to,l],i)=>i<crumbs.length-1?fmtH(`span`,{key:to},fmtH(Mn,{to},l),` › `):fmtH(`span`,{key:to,"aria-current":`page`},l))),
 fmtH(`div`,{className:`fmt-seo-grid`},
  fmtH(`div`,{},
   fmtH(`h2`,{className:`fmt-seo-h2`},`About ${pg.h1.toLowerCase().startsWith(`hotels`)?pg.h1:pg.h1}`),
   (pg.intro||[]).map((t,i)=>fmtH(`p`,{key:i,className:`fmt-seo-p`},t)),
   pg.faqs&&pg.faqs.length>0&&fmtH(`div`,{className:`fmt-faq`},fmtH(`h2`,{className:`fmt-seo-h2`},`Frequently asked questions`),pg.faqs.map(([q,a],i)=>fmtH(`details`,{key:i,className:`fmt-faq-i`},fmtH(`summary`,{},q),fmtH(`p`,{},a))))),
  rel.length>0&&fmtH(`aside`,{className:`fmt-seo-rel`},fmtH(`h2`,{className:`fmt-seo-h3`},pg.kind===`hotel`?`Hotels in other cities`:`Popular routes`),fmtH(`ul`,{},rel.map(r=>fmtH(`li`,{key:r.to},fmtH(Mn,{to:r.to},r.label)))))))}

/* ---------- B2B partner page (/partners) ---------- */
function FmtPartnersPage(){let pg=FMT_SEO.pages[`/partners`],br=fmtBrandInfo(),user=Xs(e=>e.auth.user);kY(pg.title,pg.description);
let feats=[[`💼`,`Agent fares on every service`,`Flights, hotels, buses, cabs, holidays, cruises, activities, insurance and visas from one login.`],[`📈`,`Your own markups`,`Set margins by service, route or customer. Your clients only ever see one fare.`],[`👛`,`Wallet and credit`,`Book from a prepaid or credit wallet with a full ledger, statements and top-ups by UPI, card or bank transfer.`],[`🌐`,`White-label website`,`Pick from 50+ designs, add your domain and logo, and take bookings under your own brand.`],[`🔌`,`Travel API`,`Search, book, ticket and cancel through our partner API, with sandbox keys to test.`],[`🧾`,`GST invoices and TDS`,`GST-compliant invoices and credit notes, and commission statements for your accounts.`],[`👥`,`Sub-agents and staff`,`Add sub-agents and staff users with their own limits and permissions.`],[`🏢`,`Corporate travel`,`Travel policies, trip approvals and monthly statements for company travel desks.`]],
who=[[`Travel agents`,`Book for walk-in and online clients with agent fares and instant tickets.`],[`Tour operators`,`Combine flights, hotels and transfers into your own packages.`],[`Corporates`,`Run your company's travel with policies, approvals and one monthly bill.`],[`Online resellers`,`Launch a branded booking site or plug our API into your app.`]],
steps=[[`Apply online`,`Fill in your business details and upload your KYC documents.`],[`Get approved`,`Our team verifies your details, usually within one working day.`],[`Start booking`,`Top up your wallet, set your markups and book for your clients.`]];
return fmtH(`div`,{className:`fmt-b2b`,"data-testid":`fmt-partners`},
 fmtH(`section`,{className:`fmt-b2b-hero`},fmtH(`div`,{className:`fmt-b2b-hero-in`},
  fmtH(`span`,{className:`fmt-eyebrow`},`For travel businesses`),
  fmtH(`h1`,{},pg.h1),
  fmtH(`p`,{className:`fmt-b2b-lead`},pg.intro[0]),
  fmtH(`div`,{className:`fmt-b2b-cta`},user?fmtH(Mn,{to:`/dashboard`,className:`fmt-btn fmt-btn-p`},`Go to your dashboard`):fmtH(Mn,{to:`/agent-signup`,className:`fmt-btn fmt-btn-p`},`Apply for a B2B account`),!user&&fmtH(Mn,{to:`/login`,className:`fmt-btn fmt-btn-s`},`Partner log in`)),
  fmtH(`ul`,{className:`fmt-b2b-proof`},fmtH(`li`,{},`9 travel services`),fmtH(`li`,{},`50+ website designs`),fmtH(`li`,{},`GST-ready invoicing`)))),
 fmtH(`section`,{className:`fmt-b2b-sec`},fmtH(`h2`,{},`Everything your travel business needs`),fmtH(`p`,{className:`fmt-b2b-sub`},pg.intro[1]),
  fmtH(`div`,{className:`fmt-b2b-grid`},feats.map(([i,t,d])=>fmtH(`div`,{key:t,className:`fmt-b2b-card`},fmtH(`span`,{className:`fmt-b2b-ic`,"aria-hidden":`true`},i),fmtH(`h3`,{},t),fmtH(`p`,{},d))))),
 fmtH(`section`,{className:`fmt-b2b-sec`},fmtH(`h2`,{},`Who it's for`),fmtH(`div`,{className:`fmt-b2b-who`},who.map(([t,d])=>fmtH(`div`,{key:t,className:`fmt-b2b-card`},fmtH(`h3`,{},t),fmtH(`p`,{},d))))),
 fmtH(`section`,{className:`fmt-b2b-sec`},fmtH(`h2`,{},`How to get started`),fmtH(`ol`,{className:`fmt-b2b-steps`},steps.map(([t,d],i)=>fmtH(`li`,{key:t},fmtH(`span`,{className:`fmt-b2b-n`},i+1),fmtH(`div`,{},fmtH(`h3`,{},t),fmtH(`p`,{},d)))))),
 fmtH(`section`,{className:`fmt-b2b-sec`},fmtH(`h2`,{},`Questions from partners`),fmtH(`div`,{className:`fmt-faq`},pg.faqs.map(([q,a],i)=>fmtH(`details`,{key:i,className:`fmt-faq-i`},fmtH(`summary`,{},q),fmtH(`p`,{},a))))),
 fmtH(`section`,{className:`fmt-b2b-band`},fmtH(`h2`,{},`Ready to sell travel with ${br.name}?`),fmtH(`p`,{},br.email?[`Questions? Write to `,fmtH(`a`,{href:`mailto:${br.email}`},br.email),br.phone?[` or call `,br.phone]:null,`.`]:`Apply in a few minutes. Our partner team will get in touch.`),
  !user&&fmtH(Mn,{to:`/agent-signup`,className:`fmt-btn fmt-btn-p`},`Apply for a B2B account`)))}

/* ---------- Plain-DOM helpers that live outside React's tree ----------
   1. Phones: the results filters open as a bottom sheet from a "Filters" button.
   2. Phones: during checkout a bar at the bottom shows the total and repeats
      the page's main button, so it is always one tap away.
   3. Every route: canonical link, og:url, and noindex on private pages. */
(function fmtDomAssist(){if(typeof window===`undefined`||window.__fmtAssist)return;window.__fmtAssist=1;
let mq=window.matchMedia(`(max-width: 1023px)`),doc=document.documentElement;
function el(t,c,txt){let e=document.createElement(t);c&&(e.className=c);txt!=null&&(e.textContent=txt);return e}
let fb=el(`button`,`fmt-filter-fab`);fb.type=`button`;fb.setAttribute(`data-testid`,`fmt-filter-fab`);fb.innerHTML=`<span aria-hidden="true">⚙</span> Filters`;
let scrim=el(`div`,`fmt-scrim`);
let bar=el(`div`,`fmt-paybar`);bar.setAttribute(`data-testid`,`fmt-paybar`);bar.innerHTML=`<div class="fmt-paybar-t"><span class="fmt-paybar-l">Total</span><strong class="fmt-paybar-v"></strong></div><button type="button" class="fmt-paybar-b"></button>`;
function close(){doc.classList.remove(`fmt-sheet-open`);fb.lastChild&&(fb.lastChild.textContent=` Filters`)}
fb.addEventListener(`click`,()=>{doc.classList.toggle(`fmt-sheet-open`);fb.lastChild.textContent=doc.classList.contains(`fmt-sheet-open`)?` Show results`:` Filters`;let f=document.querySelector(`.fmt-filters`);f&&f.scrollTo&&f.scrollTo(0,0)});scrim.addEventListener(`click`,close);
document.addEventListener(`keydown`,e=>{e.key===`Escape`&&close()});
let target=null;
bar.querySelector(`button`).addEventListener(`click`,()=>{if(target&&document.body.contains(target)&&!target.disabled){target.click()}else{let s=document.querySelector(`.fmt-sum`);s&&s.scrollIntoView({behavior:`smooth`,block:`center`})}});
function findTotal(){let lab=[...document.querySelectorAll(`#root span, #root div`)].filter(n=>n.children.length===0&&/^(Total payable|Estimated total|Total)$/.test(n.textContent.trim()));for(let n of lab.reverse()){let v=n.nextElementSibling,t=v?v.textContent:(n.parentElement?n.parentElement.textContent.replace(n.textContent,``):``);t=t.trim();if(/\d/.test(t)&&t.length<24)return t}return null}
function findCta(){let bs=[...document.querySelectorAll(`#root main button, #root button`)].filter(b=>b.offsetParent!==null&&!b.closest(`.fmt-paybar`)&&/^(Continue|Pay |Pay$|Proceed|I've completed|Confirm|Book now|Hold)/.test(b.textContent.trim()));return bs.length?bs[bs.length-1]:null}
let io=`IntersectionObserver`in window?new IntersectionObserver(es=>{es.forEach(e=>{bar.classList.toggle(`fmt-paybar-hide`,e.isIntersecting)})},{threshold:.6}):null,watched=null;
function tick(){try{let path=location.pathname,isCheckout=/\/checkout\/?$/.test(path),filters=document.querySelector(`.fmt-filters`),small=mq.matches;
 if(filters&&small&&!isCheckout){fb.isConnected||document.body.append(scrim,fb)}else{fb.remove(),scrim.remove(),close()}
 let confirmed=!!document.querySelector(`.fmt-hero`);
 if(isCheckout&&small&&!confirmed){let t=findTotal(),c=findCta();if(t||c){let set=(n,v)=>{n.textContent!==v&&(n.textContent=v)};set(bar.querySelector(`.fmt-paybar-v`),t||``);bar.querySelector(`.fmt-paybar-t`).style.display=t?``:`none`;let b=bar.querySelector(`button`);set(b,c?c.textContent.trim():`View fare summary`);b.disabled=!!(c&&c.disabled);target=c;if(io&&c!==watched){watched&&io.unobserve(watched),c&&io.observe(c),watched=c,c||bar.classList.remove(`fmt-paybar-hide`)}bar.isConnected||document.body.append(bar);doc.classList.add(`fmt-has-paybar`)}else{bar.remove(),doc.classList.remove(`fmt-has-paybar`)}}else{bar.remove(),doc.classList.remove(`fmt-has-paybar`)}
 fmtSeoTick(path)}catch(_){}}
let lastSeo=null;
function meta(sel,attr,name,val){let m=document.head.querySelector(sel);if(val==null){m&&m.remove();return}m||(m=document.createElement(sel.startsWith(`link`)?`link`:`meta`),sel.startsWith(`link`)?m.setAttribute(`rel`,`canonical`):m.setAttribute(attr,name),document.head.appendChild(m));m.setAttribute(sel.startsWith(`link`)?`href`:`content`,val)}
function fmtSeoTick(path){let p=path.replace(/\/+$/,``)||`/`;if(p===lastSeo)return;lastSeo=p;let priv=FMT_SEO.noindex.some(r=>p===r||p.startsWith(r+`/`))||/\/checkout$/.test(p),pg=FMT_SEO.pages[p],url=location.origin+(p===`/`?`/`:p);
 meta(`meta[name="robots"]`,`name`,`robots`,priv?`noindex, nofollow`:null);meta(`link[rel="canonical"]`,null,null,priv?null:url);meta(`meta[property="og:url"]`,`property`,`og:url`,url);
 if(pg){meta(`meta[property="og:title"]`,`property`,`og:title`,pg.title);meta(`meta[property="og:description"]`,`property`,`og:description`,pg.description)}}
new MutationObserver(ms=>{if(ms.every(m=>m.target.closest&&m.target.closest(`.fmt-paybar,.fmt-filter-fab`)||m.target.parentElement&&m.target.parentElement.closest&&m.target.parentElement.closest(`.fmt-paybar,.fmt-filter-fab`)))return;clearTimeout(window.__fmtT),window.__fmtT=setTimeout(tick,120)}).observe(document.documentElement,{childList:!0,subtree:!0,characterData:!0});
mq.addEventListener?mq.addEventListener(`change`,tick):mq.addListener(tick);window.addEventListener(`popstate`,tick);setTimeout(tick,300)})();
