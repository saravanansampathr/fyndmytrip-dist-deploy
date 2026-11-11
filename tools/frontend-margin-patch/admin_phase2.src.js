/* ===== Phase 2 and 3 screens (spliced into the portal bundle by patch_frontend.py) ===== */
function fmtP2Get(o,p){return String(p).split(`.`).reduce((a,k)=>a==null?a:a[k],o)}
function fmtP2Set(setter){return(path,v)=>setter(p=>{let n=JSON.parse(JSON.stringify(p)),o=n,k=String(path).split(`.`);for(let i=0;i<k.length-1;i++){if(o[k[i]]==null||typeof o[k[i]]!==`object`)o[k[i]]={};o=o[k[i]]}o[k[k.length-1]]=v;return n})}
function fmtP2Title(t){return fmtAsH(`h3`,{className:`text-base font-semibold text-brand-navy`},t)}
function fmtP2Note(t){return fmtAsH(`p`,{className:`text-xs text-slate-500`},t)}
function fmtP2Num(obj,set,path,label,hint,step){return fmtAsField(label,fmtAsH(`input`,{type:`number`,step:step||`any`,className:fmtAsIn,value:fmtP2Get(obj,path)??``,onChange:e=>set(path,e.target.value===``?``:Number(e.target.value))}),hint)}
function fmtP2Txt(obj,set,path,label,hint,extra){return fmtAsField(label,fmtAsH(`input`,{className:fmtAsIn,value:fmtP2Get(obj,path)??``,onChange:e=>set(path,e.target.value),...(extra||{})}),hint)}
function fmtP2Chk(obj,set,path,label){return fmtAsH(`label`,{className:`flex items-center gap-2 text-sm`},fmtAsH(`input`,{type:`checkbox`,checked:!!fmtP2Get(obj,path),onChange:e=>set(path,e.target.checked)}),label)}
function fmtP2Label(k){return String(k).replace(/_/g,` `).replace(/^./,c=>c.toUpperCase())}
function fmtP2Cell(v){if(v===null||v===void 0||v===``)return`–`;if(typeof v===`boolean`)return v?`Yes`:`No`;if(typeof v===`object`)return JSON.stringify(v);return String(v)}
function fmtP2Table(rows,cols,actions){rows=rows||[];if(!rows.length)return fmtAsH(`p`,{className:`text-sm text-slate-500`},`Nothing to show.`);cols=cols||Object.keys(rows[0]).filter(k=>typeof rows[0][k]!==`object`||rows[0][k]===null);
return fmtAsH(`div`,{className:`overflow-x-auto`},fmtAsH(`table`,{className:`w-full text-sm`},fmtAsH(`thead`,{},fmtAsH(`tr`,{className:`text-left text-slate-500`},...cols.map(c=>fmtAsH(`th`,{key:c,className:`py-1 pr-3 whitespace-nowrap`},Array.isArray(c)?c[1]:fmtP2Label(c))),actions&&fmtAsH(`th`,{key:`_a`},``))),
fmtAsH(`tbody`,{},...rows.map((r,i)=>fmtAsH(`tr`,{key:r.id??i,className:`border-t border-slate-100 align-top`},...cols.map(c=>{let k=Array.isArray(c)?c[0]:c,v=typeof k===`function`?k(r):fmtP2Get(r,k);return fmtAsH(`td`,{key:String(Array.isArray(c)?c[1]:c),className:`py-1 pr-3`},v&&typeof v===`object`&&v.$$typeof?v:fmtP2Cell(v))}),actions&&fmtAsH(`td`,{key:`_a`,className:`whitespace-nowrap text-right`},actions(r)))))))}
async function fmtP2Download(path,params,name){let r=await K.get(path,{params,responseType:`blob`});let u=URL.createObjectURL(r.data),a=document.createElement(`a`);a.href=u;a.download=name;document.body.appendChild(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(u),2e3)}
async function fmtP2Open(path){let w=window.open(``,`_blank`);try{let r=await K.get(path,{responseType:`blob`});w.location.href=URL.createObjectURL(r.data)}catch(e){w&&w.close();alert(q(e))}}
function fmtP2Save(fn,setM,ok){return async()=>{setM(null);try{let r=await fn();setM({ok:!0,text:ok||`Saved.`});return r}catch(e){setM({ok:!1,text:q(e)})}}}
function fmtP2Card(...c){return fmtAsH(X,{className:`space-y-4`},...c)}
function fmtP2Grid(...c){return fmtAsH(`div`,{className:`grid gap-4 sm:grid-cols-3`},...c)}
function fmtP2Row(...c){return fmtAsH(`div`,{className:`flex flex-wrap items-center gap-3`},...c)}
function fmtP2Sel(value,onChange,opts,cls){return fmtAsH(`select`,{className:cls||fmtAsIn,value:value??``,onChange:e=>onChange(e.target.value)},...opts.map(o=>Array.isArray(o)?(0,U.jsx)(`option`,{value:o[0],children:o[1]},o[0]):(0,U.jsx)(`option`,{value:o,children:o},o)))}
function fmtP2Map(obj){return Object.entries(obj||{}).map(([k,v])=>`${k}=${v}`).join(`, `)}
function fmtP2Parse(t){let o={};String(t||``).split(/[\n,;]+/).forEach(p=>{let[k,v]=p.split(`=`).map(s=>(s||``).trim());k&&v&&(o[k.toUpperCase()]=v.toUpperCase())});return o}

/* ---------- Platform, maintenance, currencies ---------- */
function FmtAsPlatform({data:d,currency:cu,options:o,onSaved}){
let[p,setP]=(0,x.useState)(()=>JSON.parse(JSON.stringify(d))),[c,setC]=(0,x.useState)(()=>({...cu,fixed:fmtP2Map(cu.fixed_rates),country:fmtP2Map(cu.country_currency)})),[m,setM]=(0,x.useState)(null),[m2,setM2]=(0,x.useState)(null);
let set=fmtP2Set(setP),setc=fmtP2Set(setC);
let save=fmtP2Save(async()=>{let{data:r}=await K.put(`/admin/settings/platform`,p);setP(r.data);onSaved&&onSaved()},setM,`Saved. Applies straight away.`);
let saveC=fmtP2Save(async()=>{let fx={};Object.entries(fmtP2Parse(c.fixed)).forEach(([k,v])=>fx[k]=Number(v));let{data:r}=await K.put(`/admin/settings/currency`,{enabled:c.enabled,fx_margin_percent:Number(c.fx_margin_percent||0),fixed_rates:fx,country_currency:fmtP2Parse(c.country)});setC({...r.data,fixed:fmtP2Map(r.data.fixed_rates),country:fmtP2Map(r.data.country_currency)});onSaved&&onSaved()},setM2);
let tog=k=>setC(v=>({...v,enabled:v.enabled.includes(k)?v.enabled.filter(x2=>x2!==k):[...v.enabled,k]}));
return fmtAsH(`div`,{className:`space-y-5`},
fmtP2Card(fmtP2Title(`Maintenance mode`),fmtP2Chk(p,set,`maintenance.enabled`,`Website is under maintenance (visitors can browse but not book or pay; staff can still work)`),fmtP2Txt(p,set,`maintenance.message`,`Message visitors see`)),
fmtP2Card(fmtP2Title(`Bookings and suppliers`),fmtP2Grid(
fmtP2Num(p,set,`hold_expiry_minutes`,`Cancel unpaid holds after (minutes)`,`0 = never. Holidays and visas are never auto-cancelled.`),
fmtP2Num(p,set,`ticketing_alert_hours`,`Alert when a paid booking is not ticketed within (hours of travel)`),
fmtP2Num(p,set,`search_cache_seconds`,`Keep search results for (seconds)`),
fmtP2Num(p,set,`supplier_failure_threshold`,`Pause a supplier after this many errors in a row`),
fmtP2Num(p,set,`supplier_cooldown_seconds`,`Try a paused supplier again after (seconds)`),
fmtP2Txt(p,set,`home_country`,`Home country (2 letters)`,`Decides domestic vs international.`,{maxLength:2}))),
fmtP2Row(fmtAsH(J,{onClick:save},`Save platform settings`),fmtAsMsg(m)),
fmtP2Card(fmtP2Title(`Currencies`),fmtAsH(`p`,{className:`text-sm text-slate-600`},`Base currency: ${o.base_currency}. Tick the currencies visitors may pay in.`),
fmtAsH(`div`,{className:`flex flex-wrap gap-3`},...o.currencies.map(k=>fmtAsH(`label`,{key:k,className:`flex items-center gap-1 text-sm`},fmtAsH(`input`,{type:`checkbox`,checked:c.enabled.includes(k),onChange:()=>tog(k)}),k,o.live_rates&&o.live_rates[k]?fmtAsH(`span`,{className:`text-xs text-slate-400`},` (${o.live_rates[k]})`):null))),
fmtP2Grid(fmtP2Num(c,setc,`fx_margin_percent`,`Exchange margin (%)`,`Added to the rate when someone pays in another currency.`),
fmtAsField(`Fixed rates (optional)`,fmtAsH(`input`,{className:fmtAsIn,placeholder:`USD=0.012, AED=0.044`,value:c.fixed,onChange:e=>setc(`fixed`,e.target.value)}),`One ${o.base_currency} in the other currency. Leave empty to use live rates.`),
fmtAsField(`Country → currency`,fmtAsH(`input`,{className:fmtAsIn,placeholder:`AE=AED, SG=SGD`,value:c.country,onChange:e=>setc(`country`,e.target.value)}),`Overrides the default currency for visitors from a country.`)),
fmtP2Row(fmtAsH(J,{onClick:saveC},`Save currencies`),fmtAsMsg(m2))))}

/* ---------- Fraud checks ---------- */
function FmtAsFraud({data:d,onSaved}){
let[f,setF]=(0,x.useState)(()=>({...d,domains:(d.blocked_email_domains||[]).join(`, `)})),[m,setM]=(0,x.useState)(null),[st,setSt]=(0,x.useState)(`open`);
let set=fmtP2Set(setF);
let lq=zi({queryKey:[`fmt-fraud`,st],queryFn:async()=>(await K.get(`/admin/fraud-flags`,{params:{status:st}})).data.data});
let save=fmtP2Save(async()=>{let b={...f,blocked_email_domains:f.domains.split(/[\s,;]+/).filter(Boolean)};delete b.domains;let{data:r}=await K.put(`/admin/settings/fraud`,b);setF({...r.data,domains:(r.data.blocked_email_domains||[]).join(`, `)});onSaved&&onSaved()},setM);
async function mark(id,s){try{await K.put(`/admin/fraud-flags/${id}`,{status:s});lq.refetch()}catch(e){alert(q(e))}}
return fmtAsH(`div`,{className:`space-y-5`},
fmtP2Card(fmtP2Title(`Flagged bookings`),fmtP2Row(fmtAsH(`span`,{className:`text-sm`},`Show`),fmtP2Sel(st,setSt,[[`open`,`Open`],[`reviewed`,`Reviewed`],[`cleared`,`Cleared`],[`confirmed_fraud`,`Confirmed fraud`],[`all`,`All`]],fmtAsIn+` max-w-[12rem]`)),
lq.isLoading?fmtAsH(`p`,{className:`text-sm text-slate-500`},`Loading…`):fmtP2Table(lq.data,[[`score`,`Score`],[r=>r.booking?.booking_code,`Booking`],[r=>r.booking?fmtAsMoney(r.booking.amount,r.booking.currency):``,`Amount`],[r=>r.booking?.contact_email,`Email`],[r=>r.booking?.travel_date,`Travel`],[`reason`,`Why`],[`status`,`Status`]],r=>fmtAsH(`div`,{className:`flex gap-1`},fmtAsH(J,{variant:`secondary`,onClick:()=>mark(r.id,`cleared`)},`Looks fine`),fmtAsH(J,{variant:`secondary`,onClick:()=>mark(r.id,`confirmed_fraud`)},`Fraud`))),
fmtP2Note(`Paid bookings are scored automatically. Nothing is blocked: you decide, and cancel or refund from Bookings if needed.`)),
fmtP2Card(fmtP2Title(`Rules`),fmtP2Chk(f,set,`enabled`,`Check every paid booking`),fmtP2Grid(
fmtP2Num(f,set,`review_score`,`Flag at score (10-100)`),fmtP2Num(f,set,`high_value_amount`,`High value amount (₹)`,`From a new or guest account: +40`),fmtP2Num(f,set,`new_account_hours`,`New account means younger than (hours)`),
fmtP2Num(f,set,`max_bookings_per_contact_day`,`Bookings per email/phone per day`,`More: +30`),fmtP2Num(f,set,`max_failed_payments_day`,`Failed payments per day`,`This many: +30`),fmtP2Num(f,set,`max_bookings_per_traveller_day`,`Bookings per traveller per day`,`This many: +20`)),
fmtAsField(`Throw-away email domains (+25)`,fmtAsH(`textarea`,{rows:2,className:fmtAsIn,value:f.domains,onChange:e=>set(`domains`,e.target.value)})),
fmtP2Row(fmtAsH(J,{onClick:save},`Save fraud rules`),fmtAsMsg(m))))}

/* ---------- Rewards and fare alerts ---------- */
function FmtAsRewards({loyalty:l,fareAlerts:fa,onSaved}){
let[a,setA]=(0,x.useState)(()=>JSON.parse(JSON.stringify(l))),[b,setB]=(0,x.useState)(()=>({...fa})),[m,setM]=(0,x.useState)(null);
let set=fmtP2Set(setA),setb=fmtP2Set(setB);
let save=fmtP2Save(async()=>{let r1=await K.put(`/admin/settings/loyalty`,a);let r2=await K.put(`/admin/settings/fare_alerts`,b);setA(r1.data.data);setB(r2.data.data);onSaved&&onSaved()},setM);
return fmtAsH(`div`,{className:`space-y-5`},
fmtP2Card(fmtP2Title(`Loyalty points (customers)`),fmtP2Chk(a,set,`enabled`,`Customers earn points on completed bookings`),fmtP2Grid(
fmtP2Num(a,set,`points_per_100`,`Points per ₹100 spent`),fmtP2Num(a,set,`point_value`,`Value of 1 point (₹)`),fmtP2Num(a,set,`min_redeem_points`,`Smallest number of points to use`),fmtP2Num(a,set,`expiry_months`,`Points expire after (months)`)),
fmtP2Note(`Customers move points into their wallet from My account. Points are taken back if the booking is cancelled or refunded.`)),
fmtP2Card(fmtP2Title(`Refer a friend`),fmtP2Chk(a,set,`referral.enabled`,`Customers get a referral link`),fmtP2Grid(
fmtP2Num(a,set,`referral.referrer_points`,`Points for the person who referred`),fmtP2Num(a,set,`referral.referee_points`,`Points for the new customer`),fmtP2Num(a,set,`referral.min_booking_amount`,`Their first booking must be at least (₹)`))),
fmtP2Card(fmtP2Title(`Fare drop alerts`),fmtP2Chk(b,setb,`enabled`,`Customers can save a flight route and get an email when the fare drops`),fmtP2Grid(
fmtP2Num(b,setb,`max_per_user`,`Alerts per person`),fmtP2Num(b,setb,`min_drop_percent`,`Email when the fare drops by at least (%)`))),
fmtP2Row(fmtAsH(J,{onClick:save},`Save rewards and alerts`),fmtAsMsg(m)))}

/* ---------- Roles ---------- */
function FmtAsRoles(){
let rq=zi({queryKey:[`fmt-roles`],queryFn:async()=>(await K.get(`/admin/roles`)).data.data});
let[role,setRole]=(0,x.useState)(`staff`),[sel,setSel]=(0,x.useState)(null),[m,setM]=(0,x.useState)(null);
let d=rq.data,cur=d?.roles.find(r=>r.key===role);
(0,x.useEffect)(()=>{cur&&setSel(cur.permissions.slice())},[role,d]);
if(!d)return fmtAsH(`p`,{className:`text-sm text-slate-500`},rq.error?q(rq.error):`Loading…`);
let tog=k=>setSel(s=>s.includes(k)?s.filter(v=>v!==k):[...s,k]);
let save=fmtP2Save(async()=>{await K.put(`/admin/roles/${role}`,{permissions:sel});await rq.refetch()},setM,`Saved. People with this role get these rights from their next click.`);
let reset=fmtP2Save(async()=>{if(!confirm(`Put this role back to the built-in rights?`))throw new Error(`cancel`);await K.post(`/admin/roles/${role}/reset`);await rq.refetch()},setM,`Back to the built-in rights.`);
return fmtP2Card(fmtP2Title(`What each role may do`),fmtP2Row(fmtP2Sel(role,setRole,d.roles.map(r=>[r.key,r.label+(r.customised?` (changed)`:``)]),fmtAsIn+` max-w-xs`),!d.can_edit&&fmtP2Note(`Only super admins can change roles.`)),
sel&&fmtAsH(`div`,{className:`grid gap-4 sm:grid-cols-2`},...d.catalogue.map(g=>fmtAsH(`div`,{key:g.group,className:`rounded-md border border-slate-200 p-3`},fmtAsH(`p`,{className:`mb-2 text-sm font-semibold`},g.group),...g.items.map(it=>fmtAsH(`label`,{key:it.key,className:`flex items-center gap-2 text-sm`},fmtAsH(`input`,{type:`checkbox`,disabled:!d.can_edit||role===`super_admin`,checked:sel.includes(it.key),onChange:()=>tog(it.key)}),it.label))))),
d.can_edit&&role!==`super_admin`&&fmtP2Row(fmtAsH(J,{onClick:save},`Save role`),cur?.customised&&fmtAsH(J,{variant:`secondary`,onClick:reset},`Reset to built-in`),fmtAsMsg(m)),
fmtP2Note(`Super admins can always do everything. To give one person an extra right, use Tenants → users.`))}

/* ---------- Change history ---------- */
function FmtAsHistory(){
let[f,setF]=(0,x.useState)({type:``,q:``,from:``,to:``,page:1}),[open,setOpen]=(0,x.useState)(null);
let lq=zi({queryKey:[`fmt-audit`,f],queryFn:async()=>(await K.get(`/admin/audit-logs`,{params:Object.fromEntries(Object.entries(f).filter(([,v])=>v!==``))})).data});
let set=(k,v)=>setF(p=>({...p,[k]:v,page:k===`page`?v:1}));
let d=lq.data;
return fmtP2Card(fmtP2Title(`Who changed what`),fmtP2Row(fmtP2Sel(f.type,v=>set(`type`,v),[[``,`Everything`],...(d?.types||[]).map(t=>[t,fmtP2Label(t.replace(/([a-z])([A-Z])/g,`$1 $2`))])],fmtAsIn+` max-w-[14rem]`),
fmtAsH(`input`,{className:fmtAsIn+` max-w-[12rem]`,placeholder:`Search`,value:f.q,onChange:e=>set(`q`,e.target.value)}),fmtAsH(`input`,{type:`date`,className:fmtAsIn+` max-w-[10rem]`,value:f.from,onChange:e=>set(`from`,e.target.value)}),fmtAsH(`input`,{type:`date`,className:fmtAsIn+` max-w-[10rem]`,value:f.to,onChange:e=>set(`to`,e.target.value)})),
!d?fmtAsH(`p`,{className:`text-sm text-slate-500`},lq.error?q(lq.error):`Loading…`):fmtP2Table(d.data,[[r=>new Date(r.at).toLocaleString(),`When`],[r=>r.user?r.user.name:`System`,`Who`],[`action`,`Did`],[`label`,`What`]],r=>fmtAsH(J,{variant:`secondary`,onClick:()=>setOpen(open===r.id?null:r.id)},open===r.id?`Hide`:`Details`)),
open&&d&&fmtAsH(`pre`,{className:`max-h-80 overflow-auto rounded-md bg-slate-50 p-3 text-xs`},JSON.stringify(d.data.find(r=>r.id===open)?.changes,null,2)),
d&&d.meta.last_page>1&&fmtP2Row(fmtAsH(J,{variant:`secondary`,disabled:f.page<=1,onClick:()=>set(`page`,f.page-1)},`Newer`),fmtAsH(`span`,{className:`text-sm`},`Page ${d.meta.page} of ${d.meta.last_page}`),fmtAsH(J,{variant:`secondary`,disabled:f.page>=d.meta.last_page,onClick:()=>set(`page`,f.page+1)},`Older`)))}

/* ---------- Business reports ---------- */
var fmtP2Reports=[[`profit`,`Profit (margin, GST, commission)`],[`sales`,`Sales`],[`cancellations`,`Cancellations and refunds`],[`funnel`,`Searches to bookings`],[`gstr1`,`GSTR-1 (monthly GST return)`],[`tcs`,`TCS collected (quarterly)`],[`tds`,`TDS on agent commission (quarterly)`]];
function FmtAsReports(){
let today=new Date().toISOString().slice(0,10),first=today.slice(0,8)+`01`;
let[f,setF]=(0,x.useState)({report:`profit`,from:first,to:today,group_by:`booking`,month:today.slice(0,7),financial_year:``,quarter:``}),[res,setRes]=(0,x.useState)(null),[m,setM]=(0,x.useState)(null),[busy,setBusy]=(0,x.useState)(!1);
let set=(k,v)=>setF(p=>({...p,[k]:v}));
let params=()=>f.report===`gstr1`?{month:f.month}:[`tcs`,`tds`].includes(f.report)?Object.fromEntries(Object.entries({financial_year:f.financial_year,quarter:f.quarter}).filter(([,v])=>v)):{from:f.from,to:f.to,...(f.report===`profit`?{group_by:f.group_by}:{})};
async function run(){setBusy(!0);setM(null);try{let{data:r}=await K.get(`/business-reports/${f.report}`,{params:params()});setRes(r)}catch(e){setM({ok:!1,text:q(e)})}finally{setBusy(!1)}}
async function csv(){try{await fmtP2Download(`/business-reports/${f.report}`,{...params(),format:`csv`},`${f.report}.csv`)}catch(e){setM({ok:!1,text:q(e)})}}
let body=null;if(res){if(f.report===`gstr1`)body=fmtAsH(`div`,{className:`space-y-4`},...Object.entries(res.data||{}).map(([k,v])=>fmtAsH(`div`,{key:k},fmtAsH(`p`,{className:`text-sm font-semibold uppercase`},k),fmtP2Table(Array.isArray(v)?v:[v]))),res.meta?.note&&fmtP2Note(res.meta.note));
else body=fmtAsH(`div`,{className:`space-y-2`},fmtP2Table(res.data),res.totals&&fmtAsH(`p`,{className:`text-sm font-medium`},`Totals: `+Object.entries(res.totals).map(([k,v])=>`${fmtP2Label(k)} ${v}`).join(` · `)),res.meta?.note&&fmtP2Note(res.meta.note))}
return fmtP2Card(fmtP2Title(`Reports`),fmtP2Row(fmtP2Sel(f.report,v=>{set(`report`,v);setRes(null)},fmtP2Reports,fmtAsIn+` max-w-xs`),
f.report===`gstr1`?fmtAsH(`input`,{type:`month`,className:fmtAsIn+` max-w-[11rem]`,value:f.month,onChange:e=>set(`month`,e.target.value)}):[`tcs`,`tds`].includes(f.report)?fmtAsH(`span`,{className:`flex gap-2`},fmtAsH(`input`,{className:fmtAsIn+` max-w-[8rem]`,placeholder:`FY e.g. 2026-27`,value:f.financial_year,onChange:e=>set(`financial_year`,e.target.value)}),fmtP2Sel(f.quarter,v=>set(`quarter`,v),[[``,`This quarter`],[`1`,`Q1 Apr-Jun`],[`2`,`Q2 Jul-Sep`],[`3`,`Q3 Oct-Dec`],[`4`,`Q4 Jan-Mar`]],fmtAsIn+` max-w-[10rem]`)):
fmtAsH(`span`,{className:`flex gap-2`},fmtAsH(`input`,{type:`date`,className:fmtAsIn+` max-w-[10rem]`,value:f.from,onChange:e=>set(`from`,e.target.value)}),fmtAsH(`input`,{type:`date`,className:fmtAsIn+` max-w-[10rem]`,value:f.to,onChange:e=>set(`to`,e.target.value)}),f.report===`profit`&&fmtP2Sel(f.group_by,v=>set(`group_by`,v),[[`booking`,`Per booking`],[`agent`,`Per agent`],[`partner`,`Per partner`],[`supplier`,`Per supplier`],[`service`,`Per service`],[`month`,`Per month`]],fmtAsIn+` max-w-[10rem]`)),
fmtAsH(J,{onClick:run,disabled:busy},busy?`Running…`:`Show`),fmtAsH(J,{variant:`secondary`,onClick:csv},`Download CSV`)),fmtAsMsg(m),body)}

/* ---------- Website content: banners, offers, FAQ, SEO, popular routes ---------- */
var fmtP2Types=[[`banner`,`Home banners`],[`offer`,`Offers`],[`faq`,`FAQ`],[`seo`,`SEO titles`],[`popular_route`,`Popular routes`]];
function FmtAsContent(){
let[type,setType]=(0,x.useState)(`banner`),[ed,setEd]=(0,x.useState)(null),[m,setM]=(0,x.useState)(null);
let lq=zi({queryKey:[`fmt-content`,type],queryFn:async()=>(await K.get(`/admin/content`,{params:{type}})).data});
let set=fmtP2Set(setEd);
let blank={type,title:``,body:``,image_url:``,link_url:``,module:``,data:{},sort_order:0,starts_at:``,ends_at:``,is_active:!0};
async function upload(e){let f=e.target.files[0];if(!f)return;let fd=new FormData;fd.append(`file`,f);try{let{data:r}=await K.post(`/admin/media`,fd);set(`image_url`,r.data.url)}catch(er){setM({ok:!1,text:q(er)})}}
let save=fmtP2Save(async()=>{let b={...ed};[`module`,`starts_at`,`ends_at`,`image_url`,`link_url`].forEach(k=>b[k]===``&&(b[k]=null));b.id?await K.put(`/admin/content/${b.id}`,b):await K.post(`/admin/content`,b);setEd(null);lq.refetch()},setM);
async function del(r){if(!confirm(`Remove "${r.title||r.id}"?`))return;try{await K.delete(`/admin/content/${r.id}`);lq.refetch()}catch(e){alert(q(e))}}
let form=ed&&fmtP2Card(fmtP2Title(ed.id?`Edit`:`Add`),fmtP2Grid(
type===`seo`?fmtP2Txt(ed,set,`data.path`,`Page path`,`e.g. /flights or /holidays`):fmtP2Txt(ed,set,`title`,type===`faq`?`Question`:`Title`),
type===`seo`?fmtP2Txt(ed,set,`data.meta_title`,`Page title (for Google)`):fmtAsField(`Service (optional)`,fmtP2Sel(ed.module,v=>set(`module`,v),[[``,`All`],...Object.entries(fmtAsSvc)])),
type===`seo`?fmtP2Txt(ed,set,`data.meta_description`,`Description (for Google)`):type===`offer`?fmtP2Txt(ed,set,`data.coupon_code`,`Coupon code (optional)`):type===`popular_route`?fmtP2Txt(ed,set,`data.origin`,`From (code)`):fmtP2Txt(ed,set,`link_url`,`Link (optional)`,`/flights or https://…`)),
type===`popular_route`&&fmtP2Grid(fmtP2Txt(ed,set,`data.destination`,`To (code)`),fmtP2Num(ed,set,`data.price_from`,`Price from (₹)`),fmtP2Txt(ed,set,`link_url`,`Link (optional)`)),
[`banner`,`offer`,`faq`].includes(type)&&fmtAsField(type===`faq`?`Answer`:`Text`,fmtAsH(`textarea`,{rows:3,className:fmtAsIn,value:ed.body||``,onChange:e=>set(`body`,e.target.value)})),
[`banner`,`offer`].includes(type)&&fmtP2Row(fmtAsField(`Image`,fmtAsH(`input`,{type:`file`,accept:`image/*`,onChange:upload}),`JPG, PNG or WebP up to 5 MB`),ed.image_url&&fmtAsH(`img`,{src:ed.image_url,alt:``,className:`h-16 rounded border`})),
fmtP2Grid(fmtAsField(`Show from`,fmtAsH(`input`,{type:`date`,className:fmtAsIn,value:(ed.starts_at||``).slice(0,10),onChange:e=>set(`starts_at`,e.target.value)})),fmtAsField(`Show until`,fmtAsH(`input`,{type:`date`,className:fmtAsIn,value:(ed.ends_at||``).slice(0,10),onChange:e=>set(`ends_at`,e.target.value)})),fmtP2Num(ed,set,`sort_order`,`Order (smaller first)`)),
fmtP2Chk(ed,set,`is_active`,`Visible`),fmtP2Row(fmtAsH(J,{onClick:save},`Save`),fmtAsH(J,{variant:`secondary`,onClick:()=>setEd(null)},`Cancel`),fmtAsMsg(m)));
return fmtAsH(`div`,{className:`space-y-5`},fmtP2Card(fmtP2Row(...fmtP2Types.map(([k,l])=>fmtAsH(`button`,{key:k,type:`button`,onClick:()=>{setType(k);setEd(null)},className:`rounded-md px-3 py-1.5 text-sm ${type===k?`bg-brand-teal text-white`:`bg-slate-100 text-slate-700`}`},l)),fmtAsH(J,{onClick:()=>setEd(blank)},`Add`)),
lq.isLoading?fmtAsH(`p`,{className:`text-sm`},`Loading…`):fmtP2Table(lq.data?.data,type===`seo`?[[`data.path`,`Page`],[`data.meta_title`,`Title`],[`is_active`,`Visible`]]:[[`title`,`Title`],[`module`,`Service`],[r=>(r.starts_at||``).slice(0,10),`From`],[r=>(r.ends_at||``).slice(0,10),`Until`],[`is_active`,`Visible`]],r=>fmtAsH(`div`,{className:`flex gap-1`},fmtAsH(J,{variant:`secondary`,onClick:()=>setEd({...blank,...r,data:r.data||{}})},`Edit`),fmtAsH(J,{variant:`secondary`,onClick:()=>del(r)},`Remove`))),
fmtP2Note(`Your website shows these straight away. A partner's own items replace the main site's on the partner's website.`)),form)}

/* ---------- Agent sign-ups ---------- */
function FmtAsAgentApps(){
let[st,setSt]=(0,x.useState)(`pending`),[open,setOpen]=(0,x.useState)(null),[m,setM]=(0,x.useState)(null),[exp,setExp]=(0,x.useState)({});
let lq=zi({queryKey:[`fmt-agent-apps`,st],queryFn:async()=>(await K.get(`/admin/agent-applications`,{params:{status:st}})).data.data});
let dq=zi({queryKey:[`fmt-agent-app`,open],queryFn:async()=>(await K.get(`/admin/agent-applications/${open}`)).data.data,enabled:!!open});
async function approve(){setM(null);try{let ex=Object.fromEntries(Object.entries(exp).filter(([,v])=>v));let{data:r}=await K.post(`/admin/agent-applications/${open}/approve`,{expiry:ex});setM({ok:!0,text:r.message});setOpen(null);lq.refetch()}catch(e){setM({ok:!1,text:q(e)})}}
async function reject(){let reason=prompt(`Why is it rejected? The applicant gets this in an email.`);if(!reason)return;setM(null);try{let{data:r}=await K.post(`/admin/agent-applications/${open}/reject`,{reason});setM({ok:!0,text:r.message});setOpen(null);lq.refetch()}catch(e){setM({ok:!1,text:q(e)})}}
let a=dq.data;
return fmtAsH(`div`,{className:`space-y-5`},fmtP2Card(fmtP2Title(`Agents who applied`),fmtP2Row(fmtP2Sel(st,v=>{setSt(v);setOpen(null)},[[`pending`,`Waiting`],[`approved`,`Approved`],[`rejected`,`Rejected`]],fmtAsIn+` max-w-[12rem]`),fmtAsH(`span`,{className:`text-sm text-slate-500`},`Sign-up page for agents: `,fmtAsH(`a`,{href:`/agent-signup`,target:`_blank`,className:`text-brand-teal-dark underline`},location.origin+`/agent-signup`))),
fmtAsMsg(m),lq.isLoading?fmtAsH(`p`,{className:`text-sm`},`Loading…`):fmtP2Table(lq.data,[[`company_name`,`Company`],[`contact_name`,`Contact`],[`email`,`Email`],[`phone`,`Phone`],[`city`,`City`],[r=>new Date(r.created_at).toLocaleDateString(),`Applied`]],r=>fmtAsH(J,{variant:`secondary`,onClick:()=>{setOpen(r.id);setExp({})}},`Open`))),
open&&fmtP2Card(!a?fmtAsH(`p`,{className:`text-sm`},`Loading…`):fmtAsH(`div`,{className:`space-y-3`},fmtP2Title(a.company_name),
fmtAsH(`p`,{className:`text-sm`},`${a.contact_name} · ${a.email} · ${a.phone}`),fmtAsH(`p`,{className:`text-sm`},`PAN ${a.pan}`+(a.gstin?` · GSTIN ${a.gstin}`:``)),fmtAsH(`p`,{className:`text-sm text-slate-600`},`${a.address}, ${a.city}, ${a.state}`),
fmtAsH(`div`,{className:`space-y-2`},...(a.document_list||[]).map(doc=>fmtP2Row(fmtAsH(J,{key:doc.id,variant:`secondary`,onClick:()=>fmtP2Open(doc.file_url.replace(/^.*\/api/,``))},`View ${doc.label||doc.type}`),a.status===`pending`&&fmtAsH(`label`,{className:`text-xs text-slate-500`},`Expires on `,fmtAsH(`input`,{type:`date`,className:`rounded border px-1`,value:exp[doc.type]||``,onChange:e=>setExp({...exp,[doc.type]:e.target.value})}))))),
a.status===`pending`?fmtP2Row(fmtAsH(J,{onClick:approve},`Approve and create the account`),fmtAsH(J,{variant:`secondary`,onClick:reject},`Reject`)):fmtAsH(`p`,{className:`text-sm`},`Status: ${a.status}`+(a.rejection_reason?` (${a.rejection_reason})`:``)))))}

/* ---------- Analytics and ads tags ---------- */
function FmtAsTags(){
let lq=zi({queryKey:[`fmt-tags`],queryFn:async()=>(await K.get(`/marketing-tags`)).data}),[t,setT]=(0,x.useState)(null),[m,setM]=(0,x.useState)(null);
(0,x.useEffect)(()=>{lq.data&&setT({...lq.data.data})},[lq.data]);
let set=fmtP2Set(setT);
let save=fmtP2Save(async()=>{let b={};Object.entries(t).forEach(([k,v])=>b[k]=v||null);await K.put(`/marketing-tags`,b);lq.refetch()},setM,`Saved. Your website loads these tags from the next page visit.`);
if(!t)return fmtAsH(`p`,{className:`text-sm`},`Loading…`);
return fmtP2Card(fmtP2Title(`Analytics and ads tracking for ${lq.data.tenant.name}`),fmtP2Grid(
fmtP2Txt(t,set,`ga4_id`,`Google Analytics 4 ID`,`Looks like G-XXXXXXX`),fmtP2Txt(t,set,`gtm_id`,`Google Tag Manager ID`,`Looks like GTM-XXXXXX`),fmtP2Txt(t,set,`meta_pixel_id`,`Meta (Facebook) Pixel ID`,`15-16 digits`),
fmtP2Txt(t,set,`google_ads_id`,`Google Ads ID`,`Looks like AW-123456789`),fmtP2Txt(t,set,`google_ads_purchase_label`,`Google Ads purchase conversion label`)),
fmtP2Note(`Only the IDs are saved. The website adds the standard tag for each, and reports a purchase when a booking is paid.`),fmtP2Row(fmtAsH(J,{onClick:save},`Save tags`),fmtAsMsg(m)))}

/* ---------- Webhooks (partners and API customers) ---------- */
function FmtWebhooksPage(){
kY(`Webhooks`);
let lq=zi({queryKey:[`fmt-webhooks`],queryFn:async()=>(await K.get(`/webhooks`)).data}),[f,setF]=(0,x.useState)({url:``,events:[]}),[m,setM]=(0,x.useState)(null),[secret,setSecret]=(0,x.useState)(null),[dl,setDl]=(0,x.useState)(null);
let ev0=lq.data?.events||[],events=Array.isArray(ev0)?ev0:Object.keys(ev0);
async function add(){setM(null);try{let{data:r}=await K.post(`/webhooks`,{url:f.url,events:f.events.length?f.events:events});setSecret(r.data.secret);setF({url:``,events:[]});lq.refetch()}catch(e){setM({ok:!1,text:q(e)})}}
async function act(fn){setM(null);try{let r=await fn();lq.refetch();return r}catch(e){setM({ok:!1,text:q(e)})}}
async function deliveries(id){try{let{data:r}=await K.get(`/webhooks/${id}/deliveries`);setDl({id,rows:r.data})}catch(e){setM({ok:!1,text:q(e)})}}
return fmtAsH(`div`,{className:`mx-auto max-w-4xl space-y-5`},fmtAsH(`h1`,{className:`text-2xl font-bold text-brand-navy`},`Webhooks`),
fmtAsH(`p`,{className:`text-sm text-slate-600`},`We send a signed POST to your URL when a booking is confirmed, ticketed, cancelled, refunded or fails. Check the X-FyndMyTrip-Signature header: sha256= HMAC-SHA256 of "timestamp.body" with your secret. Failed sends are retried for a day.`),
fmtP2Card(fmtP2Title(`Add an endpoint`),fmtAsField(`HTTPS URL`,fmtAsH(`input`,{className:fmtAsIn,placeholder:`https://example.com/fyndmytrip-webhook`,value:f.url,onChange:e=>setF({...f,url:e.target.value})})),
fmtAsH(`div`,{className:`flex flex-wrap gap-3`},...events.map(ev=>fmtAsH(`label`,{key:ev,className:`flex items-center gap-1 text-sm`},fmtAsH(`input`,{type:`checkbox`,checked:f.events.includes(ev),onChange:()=>setF({...f,events:f.events.includes(ev)?f.events.filter(v=>v!==ev):[...f.events,ev]})}),ev))),fmtP2Note(`No events ticked means all events.`),
fmtP2Row(fmtAsH(J,{onClick:add,disabled:!f.url||!events.length},`Add`),fmtAsMsg(m)),secret&&fmtAsH(`div`,{className:`rounded-md bg-amber-50 p-3 text-sm`},`Signing secret (shown once, copy it now): `,fmtAsH(`code`,{className:`select-all`},secret))),
fmtP2Card(fmtP2Title(`Your endpoints`),fmtP2Table(lq.data?.data,[[`url`,`URL`],[r=>(r.events||[]).join(`, `),`Events`],[`is_active`,`On`],[`failure_count`,`Failures`]],r=>fmtAsH(`div`,{className:`flex gap-1`},
fmtAsH(J,{variant:`secondary`,onClick:()=>act(async()=>{let{data:x2}=await K.post(`/webhooks/${r.id}/test`);setM({ok:x2.data.delivered,text:x2.data.delivered?`Test delivered (${x2.data.response_code}).`:`Test failed: ${x2.data.error||x2.data.response_code}`})})},`Test`),
fmtAsH(J,{variant:`secondary`,onClick:()=>deliveries(r.id)},`Log`),
fmtAsH(J,{variant:`secondary`,onClick:()=>act(()=>K.put(`/webhooks/${r.id}`,{is_active:!r.is_active}))},r.is_active?`Pause`:`Resume`),
fmtAsH(J,{variant:`secondary`,onClick:()=>confirm(`New secret? The old one stops working at once.`)&&act(async()=>{let{data:x2}=await K.put(`/webhooks/${r.id}`,{rotate_secret:!0});setSecret(x2.data.secret)})},`New secret`),
fmtAsH(J,{variant:`secondary`,onClick:()=>confirm(`Remove this endpoint?`)&&act(()=>K.delete(`/webhooks/${r.id}`))},`Remove`))),
dl&&fmtAsH(`div`,{className:`space-y-2`},fmtAsH(`p`,{className:`text-sm font-semibold`},`Recent sends`),fmtP2Table(dl.rows,[[`event`,`Event`],[`status`,`Status`],[`attempts`,`Tries`],[`response_code`,`HTTP`],[`error`,`Error`],[r=>new Date(r.created_at).toLocaleString(),`When`]],r=>r.status!==`delivered`&&fmtAsH(J,{variant:`secondary`,onClick:()=>act(async()=>{await K.post(`/webhook-deliveries/${r.id}/retry`);deliveries(dl.id)})},`Retry`)))))}

/* ---------- Offline bookings ---------- */
function FmtOfflinePage(){
kY(`Offline bookings`);
let u=Xs(e=>e.auth.user),staff=[`super_admin`,`staff`,`partner_admin`].includes(u?.role);
let blank={tenant_id:``,module:`flight`,supplier_name:``,supplier_ref:``,travel_date_start:``,travel_date_end:``,travellers:``,contact_email:``,contact_phone:``,supplier_amount:``,selling_amount:``,payment_mode:`bank`,status:`ticketed`,is_international:!1,notes:``,notify_customer:!1};
let[f,setF]=(0,x.useState)(blank),[m,setM]=(0,x.useState)(null),[imp,setImp]=(0,x.useState)(null);
let set=fmtP2Set(setF);
let save=fmtP2Save(async()=>{let b={...f,travellers:f.travellers.split(/[\n;,]+/).map(s=>s.trim()).filter(Boolean)};Object.keys(b).forEach(k=>b[k]===``&&delete b[k]);let{data:r}=await K.post(`/offline-bookings`,b);setF(blank);return r},setM,`Saved. The booking and its invoice are in Bookings.`);
async function importCsv(e){let file=e.target.files[0];if(!file)return;let fd=new FormData;fd.append(`file`,file);setImp(null);try{let{data:r}=await K.post(`/offline-bookings/import`,fd);setImp(r)}catch(er){setImp({message:q(er),data:[]})}e.target.value=``}
return fmtAsH(`div`,{className:`mx-auto max-w-4xl space-y-5`},fmtAsH(`h1`,{className:`text-2xl font-bold text-brand-navy`},`Offline bookings`),
fmtAsH(`p`,{className:`text-sm text-slate-600`},`Record a booking made by phone, on another portal or directly with an airline or hotel, so it is in your reports, invoices and GST returns.`),
fmtP2Card(fmtP2Title(`Add one booking`),fmtP2Grid(
fmtAsField(`Service`,fmtP2Sel(f.module,v=>set(`module`,v),Object.entries(fmtAsSvc))),fmtP2Txt(f,set,`supplier_name`,`Booked with (airline, hotel, portal)`),fmtP2Txt(f,set,`supplier_ref`,`PNR / confirmation number`),
fmtAsField(`Travel date`,fmtAsH(`input`,{type:`date`,className:fmtAsIn,value:f.travel_date_start,onChange:e=>set(`travel_date_start`,e.target.value)})),fmtAsField(`Return / check-out (optional)`,fmtAsH(`input`,{type:`date`,className:fmtAsIn,value:f.travel_date_end,onChange:e=>set(`travel_date_end`,e.target.value)})),
fmtAsField(`Status`,fmtP2Sel(f.status,v=>set(`status`,v),[[`ticketed`,`Ticket / voucher issued`],[`confirmed`,`Confirmed, not issued yet`]])),
fmtP2Num(f,set,`supplier_amount`,`Cost price (paid to supplier)`),fmtP2Num(f,set,`selling_amount`,`Selling price (customer paid)`),fmtAsField(`Payment received by`,fmtP2Sel(f.payment_mode,v=>set(`payment_mode`,v),[[`bank`,`Bank transfer`],[`upi`,`UPI`],[`card`,`Card`],[`cash`,`Cash`],[`cheque`,`Cheque`],[`credit`,`On credit`]])),
fmtP2Txt(f,set,`contact_email`,`Customer email`),fmtP2Txt(f,set,`contact_phone`,`Customer phone`),staff&&fmtP2Txt(f,set,`tenant_id`,`Account ID (optional)`,`Leave empty for your own account`)),
fmtAsField(`Travellers (one per line)`,fmtAsH(`textarea`,{rows:2,className:fmtAsIn,value:f.travellers,onChange:e=>set(`travellers`,e.target.value)})),fmtP2Txt(f,set,`notes`,`Notes`),
fmtP2Row(fmtP2Chk(f,set,`is_international`,`International`),fmtP2Chk(f,set,`notify_customer`,`Email the customer their confirmation`)),fmtP2Row(fmtAsH(J,{onClick:save},`Save booking`),fmtAsMsg(m))),
fmtP2Card(fmtP2Title(`Import many from a spreadsheet`),fmtP2Row(fmtAsH(J,{variant:`secondary`,onClick:()=>fmtP2Download(`/offline-bookings/template`,{},`offline-bookings-template.csv`)},`Download the CSV template`),fmtAsH(`input`,{type:`file`,accept:`.csv,text/csv`,onChange:importCsv})),
fmtP2Note(`Up to 500 rows. Each row is saved on its own; rows with mistakes are listed below.`),imp&&fmtAsH(`div`,{className:`space-y-2`},fmtAsH(`p`,{className:`text-sm font-medium`},imp.message),fmtP2Table((imp.data||[]).filter(r=>!r.ok),[[`line`,`Line`],[`error`,`Problem`]]))))}

/* ---------- Corporate travel ---------- */
function FmtCorporatePage(){
kY(`Corporate travel`);
let u=Xs(e=>e.auth.user),owner=[`agent`,`sub_agent`].includes(u?.role),staff=[`super_admin`,`staff`,`partner_admin`].includes(u?.role);
let[cid,setCid]=(0,x.useState)(owner?String(u.tenant_id):``),[m,setM]=(0,x.useState)(null),[m2,setM2]=(0,x.useState)(null),[month,setMonth]=(0,x.useState)(new Date().toISOString().slice(0,7)),[stm,setStm]=(0,x.useState)(null);
let blank={travellers:u?.name||``,module:`flight`,origin:``,destination:``,depart_date:``,return_date:``,cabin_class:`economy`,estimated_amount:``,nights:``,purpose:``,cost_center:``};
let[f,setF]=(0,x.useState)(blank),set=fmtP2Set(setF);
let lq=zi({queryKey:[`fmt-trips`],queryFn:async()=>(await K.get(`/corporate/trip-requests`)).data,retry:!1});
let sq=zi({queryKey:[`fmt-corp`,cid],queryFn:async()=>(await K.get(`/corporate/companies/${cid}`)).data.data,enabled:!!cid&&(owner||staff),retry:!1});
let[pol,setPol]=(0,x.useState)(null),setp=fmtP2Set(setPol);(0,x.useEffect)(()=>{sq.data&&setPol(JSON.parse(JSON.stringify(sq.data)))},[sq.data]);
let send=fmtP2Save(async()=>{let b={...f};Object.keys(b).forEach(k=>b[k]===``&&delete b[k]);let{data:r}=await K.post(`/corporate/trip-requests`,b);setF(blank);lq.refetch();setM({ok:!0,text:r.message});return r},setM,``);
async function decide(r,d){let note=d===`reject`?prompt(`Why not? The traveller sees this.`):``;if(d===`reject`&&!note)return;try{await K.post(`/corporate/trip-requests/${r.id}/${d}`,{note});lq.refetch()}catch(e){alert(q(e))}}
async function link(r){let code=prompt(`Booking ID used for this trip (e.g. FMTFL...)`);if(!code)return;try{await K.post(`/corporate/trip-requests/${r.id}/link`,{booking_code:code.trim()});lq.refetch()}catch(e){alert(q(e))}}
let savePol=fmtP2Save(async()=>{let b={policy:pol.policy,approver_ids:pol.approver_ids,monthly_billing:pol.monthly_billing};staff&&(b.enabled=pol.enabled);let{data:r}=await K.put(`/corporate/companies/${cid}`,b);setPol(r.data);lq.refetch()},setM2);
async function statement(csv){try{if(csv)return fmtP2Download(`/corporate/companies/${cid}/statement`,{month,format:`csv`},`statement-${month}.csv`);let{data:r}=await K.get(`/corporate/companies/${cid}/statement`,{params:{month}});setStm(r.data)}catch(e){setM2({ok:!1,text:q(e)})}}
let off=lq.error&&!staff;
return fmtAsH(`div`,{className:`mx-auto max-w-5xl space-y-5`},fmtAsH(`h1`,{className:`text-2xl font-bold text-brand-navy`},`Corporate travel`),
off?fmtAsH(`p`,{className:`text-sm text-slate-600`},q(lq.error)+` Ask your travel manager to switch on corporate travel for your company.`):fmtAsH(`div`,{className:`space-y-5`},
!staff&&fmtP2Card(fmtP2Title(`Ask to travel`),fmtP2Grid(fmtP2Txt(f,set,`travellers`,`Traveller name(s)`),fmtAsField(`Service`,fmtP2Sel(f.module,v=>set(`module`,v),Object.entries(fmtAsSvc))),fmtP2Txt(f,set,`purpose`,`Purpose of the trip`),
fmtP2Txt(f,set,`origin`,`From`),fmtP2Txt(f,set,`destination`,`To / hotel city`),fmtAsField(`Date`,fmtAsH(`input`,{type:`date`,className:fmtAsIn,value:f.depart_date,onChange:e=>set(`depart_date`,e.target.value)})),
fmtAsField(`Return (optional)`,fmtAsH(`input`,{type:`date`,className:fmtAsIn,value:f.return_date,onChange:e=>set(`return_date`,e.target.value)})),f.module===`flight`?fmtAsField(`Class`,fmtP2Sel(f.cabin_class,v=>set(`cabin_class`,v),[[`economy`,`Economy`],[`premium_economy`,`Premium economy`],[`business`,`Business`],[`first`,`First`]])):fmtP2Num(f,set,`nights`,`Nights`),
fmtP2Num(f,set,`estimated_amount`,`Expected cost (₹)`),fmtP2Txt(f,set,`cost_center`,`Cost centre (optional)`)),fmtP2Row(fmtAsH(J,{onClick:send},`Send for approval`),fmtAsMsg(m))),
fmtP2Card(fmtP2Title(`Trip requests`),lq.isLoading?fmtAsH(`p`,{className:`text-sm`},`Loading…`):fmtP2Table(lq.data?.data,[[r=>r.requester?.name,`By`],[`travellers`,`Travellers`],[r=>(r.origin?r.origin+` → `:``)+r.destination,`Trip`],[r=>(r.depart_date||``).slice(0,10),`Date`],[`estimated_amount`,`Cost`],[r=>(r.policy_issues||[]).length?fmtAsH(`span`,{className:`text-amber-700`},r.policy_issues.join(` `)):`Within policy`,`Policy`],[`status`,`Status`]],
r=>fmtAsH(`div`,{className:`flex gap-1`},r.status===`pending`&&lq.data?.can_approve&&fmtAsH(J,{onClick:()=>decide(r,`approve`)},`Approve`),r.status===`pending`&&lq.data?.can_approve&&fmtAsH(J,{variant:`secondary`,onClick:()=>decide(r,`reject`)},`Reject`),r.status===`approved`&&fmtAsH(J,{variant:`secondary`,onClick:()=>link(r)},`Add booking ID`)))),
(owner||staff)&&fmtP2Card(fmtP2Title(`Company settings`),staff&&fmtP2Txt({cid},(k,v)=>setCid(v),`cid`,`Company account ID`),
pol&&fmtAsH(`div`,{className:`space-y-4`},staff&&fmtP2Chk(pol,setp,`enabled`,`Corporate travel is on for this company`),fmtP2Grid(
fmtP2Num(pol,setp,`policy.flight_max_amount`,`Flight limit per trip (₹)`),fmtP2Num(pol,setp,`policy.hotel_max_per_night`,`Hotel limit per night (₹)`),fmtP2Num(pol,setp,`policy.min_advance_days`,`Book at least (days ahead)`),
fmtP2Num(pol,setp,`policy.auto_approve_below`,`Approve automatically below (₹, within policy)`,`0 = always ask an approver`)),
fmtAsH(`div`,{className:`flex flex-wrap gap-3`},fmtAsH(`span`,{className:`text-sm`},`Allowed flight classes:`),...[`economy`,`premium_economy`,`business`,`first`].map(c=>fmtAsH(`label`,{key:c,className:`flex items-center gap-1 text-sm`},fmtAsH(`input`,{type:`checkbox`,checked:(pol.policy.allowed_cabins||[]).includes(c),onChange:()=>setp(`policy.allowed_cabins`,(pol.policy.allowed_cabins||[]).includes(c)?pol.policy.allowed_cabins.filter(v=>v!==c):[...(pol.policy.allowed_cabins||[]),c])}),fmtP2Label(c)))),
fmtAsH(`div`,{className:`flex flex-wrap gap-3`},fmtAsH(`span`,{className:`text-sm`},`Approvers:`),...(pol.users||[]).map(p=>fmtAsH(`label`,{key:p.id,className:`flex items-center gap-1 text-sm`},fmtAsH(`input`,{type:`checkbox`,checked:(pol.approver_ids||[]).includes(p.id),onChange:()=>setp(`approver_ids`,(pol.approver_ids||[]).includes(p.id)?pol.approver_ids.filter(v=>v!==p.id):[...(pol.approver_ids||[]),p.id])}),p.name)),fmtP2Note(`None ticked: the account owners approve.`)),
fmtP2Row(fmtAsH(J,{onClick:savePol},`Save company settings`),fmtAsMsg(m2)),
fmtP2Title(`Monthly statement`),fmtP2Row(fmtAsH(`input`,{type:`month`,className:fmtAsIn+` max-w-[11rem]`,value:month,onChange:e=>setMonth(e.target.value)}),fmtAsH(J,{variant:`secondary`,onClick:()=>statement(!1)},`Show`),fmtAsH(J,{variant:`secondary`,onClick:()=>statement(!0)},`Download CSV`)),
stm&&fmtAsH(`div`,{className:`space-y-2`},fmtP2Table(stm.rows),fmtAsH(`p`,{className:`text-sm font-medium`},`Total ${fmtAsMoney(stm.total)} (GST ${fmtAsMoney(stm.gst)})`))),sq.error&&fmtAsH(`p`,{className:`text-sm text-red-600`},q(sq.error)))))}

/* ---------- My account: rewards, fare alerts, privacy ---------- */
function FmtMyAccountPage(){
kY(`Rewards & privacy`);
let u=Xs(e=>e.auth.user),cust=u?.role===`customer`;
let lq=zi({queryKey:[`fmt-loyalty`],queryFn:async()=>(await K.get(`/loyalty`)).data.data,enabled:cust});
let aq=zi({queryKey:[`fmt-alerts`],queryFn:async()=>(await K.get(`/fare-alerts`)).data.data,enabled:cust});
let pq=zi({queryKey:[`fmt-privacy`],queryFn:async()=>(await K.get(`/privacy`)).data.data});
let[pts,setPts]=(0,x.useState)(``),[m,setM]=(0,x.useState)(null),[m2,setM2]=(0,x.useState)(null),[m3,setM3]=(0,x.useState)(null),[al,setAl]=(0,x.useState)({origin:``,destination:``,depart_date:``,target_price:``});
let redeem=fmtP2Save(async()=>{let{data:r}=await K.post(`/loyalty/redeem`,{points:Number(pts)});setPts(``);lq.refetch();setM({ok:!0,text:r.message})},setM,``);
let addAlert=fmtP2Save(async()=>{let b={...al};b.target_price===``&&delete b.target_price;await K.post(`/fare-alerts`,b);setAl({origin:``,destination:``,depart_date:``,target_price:``});aq.refetch()},setM2,`We will email you when the fare drops.`);
async function consent(v){setM3(null);try{let{data:r}=await K.put(`/privacy/consent`,{marketing:v});pq.refetch();setM3({ok:!0,text:r.message})}catch(e){setM3({ok:!1,text:q(e)})}}
async function del(){let pw=prompt(`This deletes your login for good. Enter your password to continue:`);if(!pw)return;if(prompt(`Type DELETE to confirm`)!==`DELETE`)return;try{let{data:r}=await K.post(`/privacy/delete-account`,{password:pw,confirm:`DELETE`});alert(r.message);qs.dispatch(Bs());location.href=`/`}catch(e){setM3({ok:!1,text:q(e)})}}
let l=lq.data,p=pq.data;
return fmtAsH(`div`,{className:`mx-auto max-w-3xl space-y-5`},fmtAsH(`h1`,{className:`text-2xl font-bold text-brand-navy`},`Rewards & privacy`),
cust&&l?.enabled&&fmtP2Card(fmtP2Title(`Your points`),fmtAsH(`p`,{className:`text-3xl font-bold text-brand-teal-dark`},`${l.points} points`),fmtAsH(`p`,{className:`text-sm text-slate-600`},`Worth ${fmtAsMoney(l.worth)}. You earn ${l.points_per_100} point(s) for every ₹100 you spend; points last ${l.expiry_months} months.`),
fmtP2Row(fmtAsH(`input`,{type:`number`,className:fmtAsIn+` max-w-[10rem]`,placeholder:`Min ${l.min_redeem_points}`,value:pts,onChange:e=>setPts(e.target.value)}),fmtAsH(J,{onClick:redeem,disabled:!pts},`Move to my wallet`),fmtAsMsg(m)),
l.referral&&fmtAsH(`div`,{className:`rounded-md bg-slate-50 p-3 text-sm`},fmtAsH(`p`,{className:`font-medium`},`Invite friends: you get ${l.referral.you_get} points and they get ${l.referral.friend_gets} when they make their first booking.`),fmtAsH(`p`,{},`Your code: `,fmtAsH(`code`,{className:`select-all`},l.referral.code),` · Link: `,fmtAsH(`code`,{className:`select-all`},l.referral.link)),fmtAsH(`p`,{className:`text-xs text-slate-500`},`${l.referral.friends_joined} friend(s) joined so far.`)),
fmtP2Table((l.history||[]).slice(0,20),[[r=>new Date(r.created_at).toLocaleDateString(),`Date`],[`points`,`Points`],[`note`,`What for`]])),
cust&&fmtP2Card(fmtP2Title(`Fare drop alerts`),fmtP2Note(`Save a flight route and date; we email you when the price drops.`),fmtP2Row(
fmtAsH(`input`,{className:fmtAsIn+` max-w-[6rem]`,placeholder:`From (MAA)`,maxLength:3,value:al.origin,onChange:e=>setAl({...al,origin:e.target.value.toUpperCase()})}),fmtAsH(`input`,{className:fmtAsIn+` max-w-[6rem]`,placeholder:`To (DEL)`,maxLength:3,value:al.destination,onChange:e=>setAl({...al,destination:e.target.value.toUpperCase()})}),
fmtAsH(`input`,{type:`date`,className:fmtAsIn+` max-w-[10rem]`,value:al.depart_date,onChange:e=>setAl({...al,depart_date:e.target.value})}),fmtAsH(`input`,{type:`number`,className:fmtAsIn+` max-w-[9rem]`,placeholder:`Target ₹ (optional)`,value:al.target_price,onChange:e=>setAl({...al,target_price:e.target.value})}),fmtAsH(J,{onClick:addAlert},`Add alert`)),fmtAsMsg(m2),
fmtP2Table(aq.data,[[r=>r.origin+` → `+r.destination,`Route`],[r=>(r.depart_date||``).slice(0,10),`Date`],[`last_price`,`Last price`],[`target_price`,`Target`]],r=>fmtAsH(J,{variant:`secondary`,onClick:async()=>{await K.delete(`/fare-alerts/${r.id}`);aq.refetch()}},`Remove`))),
fmtP2Card(fmtP2Title(`Privacy`),p&&fmtAsH(`label`,{className:`flex items-center gap-2 text-sm`},fmtAsH(`input`,{type:`checkbox`,checked:!!p.marketing_consent,onChange:e=>consent(e.target.checked)}),`Send me offers and deals by email, SMS and WhatsApp`),
fmtP2Note(`Booking messages (tickets, changes, refunds) are always sent.`),fmtP2Row(fmtAsH(J,{variant:`secondary`,onClick:()=>fmtP2Download(`/privacy/export`,{},`my-data.json`).catch(e=>setM3({ok:!1,text:q(e)}))},`Download my data`),
cust&&fmtAsH(J,{variant:`secondary`,onClick:del,disabled:p&&!p.can_delete},`Delete my account`)),p&&!p.can_delete&&p.delete_blocked_reason&&fmtP2Note(p.delete_blocked_reason),fmtAsMsg(m3)))}

/* ---------- Public: travel agent sign-up ---------- */
function FmtAgentSignup(){
kY(`Become a travel agent`);
let[f,setF]=(0,x.useState)({company_name:``,contact_name:``,email:``,phone:``,password:``,pan:``,gstin:``,address:``,city:``,state:``,consent:!1}),[files,setFiles]=(0,x.useState)({}),[m,setM]=(0,x.useState)(null),[busy,setBusy]=(0,x.useState)(!1),[done,setDone]=(0,x.useState)(null);
let set=fmtP2Set(setF);
let docs=[[`pan`,`PAN card`,!0],[`id_proof`,`Owner ID proof (Aadhaar, passport)`,!0],[`gst`,`GST certificate`,!1],[`address_proof`,`Office address proof`,!1],[`iata`,`IATA certificate`,!1]];
async function submit(e){e.preventDefault();setBusy(!0);setM(null);let fd=new FormData;Object.entries(f).forEach(([k,v])=>fd.append(k,k===`consent`?(v?`1`:`0`):v));Object.entries(files).forEach(([k,v])=>v&&fd.append(`documents[${k}]`,v));
try{let{data:r}=await K.post(`/agent-signup`,fd);setDone(r.message)}catch(er){setM({ok:!1,text:q(er)})}finally{setBusy(!1)}}
if(done)return fmtAsH(`div`,{className:`mx-auto max-w-xl p-6`},fmtP2Card(fmtP2Title(`Application received`),fmtAsH(`p`,{className:`text-sm`},done),fmtAsH(Mn,{to:`/login`,className:`text-sm font-medium text-brand-teal-dark underline`},`Back to sign in`)));
return fmtAsH(`div`,{className:`mx-auto max-w-3xl p-4 sm:p-6`},fmtAsH(`form`,{onSubmit:submit,className:`space-y-5`},fmtAsH(`h1`,{className:`text-2xl font-bold text-brand-navy`},`Become a travel agent`),
fmtAsH(`p`,{className:`text-sm text-slate-600`},`Apply for a B2B agent account. We check your documents and email you, usually within one working day.`),
fmtP2Card(fmtP2Grid(fmtP2Txt(f,set,`company_name`,`Company name`),fmtP2Txt(f,set,`contact_name`,`Your name`),fmtP2Txt(f,set,`email`,`Email`,null,{type:`email`}),fmtP2Txt(f,set,`phone`,`Mobile`),fmtP2Txt(f,set,`password`,`Choose a password`,`At least 8 characters`,{type:`password`}),
fmtP2Txt(f,set,`pan`,`Company or owner PAN`,null,{maxLength:10}),fmtP2Txt(f,set,`gstin`,`GSTIN (optional)`,null,{maxLength:15}),fmtP2Txt(f,set,`city`,`City`),fmtP2Txt(f,set,`state`,`State`)),
fmtAsField(`Office address`,fmtAsH(`textarea`,{rows:2,className:fmtAsIn,value:f.address,onChange:e=>set(`address`,e.target.value)}))),
fmtP2Card(fmtP2Title(`Documents (PDF, JPG or PNG, up to 5 MB each)`),fmtAsH(`div`,{className:`grid gap-3 sm:grid-cols-2`},...docs.map(([k,l,req])=>fmtAsField(l+(req?``:` (optional)`),fmtAsH(`input`,{type:`file`,accept:`.pdf,.jpg,.jpeg,.png`,onChange:e=>setFiles({...files,[k]:e.target.files[0]})}))))),
fmtAsH(`label`,{className:`flex items-start gap-2 text-sm`},fmtAsH(`input`,{type:`checkbox`,checked:f.consent,onChange:e=>set(`consent`,e.target.checked)}),fmtAsH(`span`,{},`I agree to the `,fmtAsH(`a`,{href:`/terms-and-conditions`,target:`_blank`,className:`underline`},`terms`),` and the `,fmtAsH(`a`,{href:`/privacy-policy`,target:`_blank`,className:`underline`},`privacy policy`),`, and allow my documents to be checked.`)),
fmtP2Row(fmtAsH(J,{type:`submit`,disabled:busy},busy?`Sending…`:`Apply`),fmtAsMsg(m))))}

/* Remember ?ref=CODE from a referral link and send it with the sign-up. */
try{let r=new URLSearchParams(location.search).get(`ref`)||new URLSearchParams((location.hash.split(`?`)[1])||``).get(`ref`);r&&localStorage.setItem(`fmt_ref`,r)}catch(_){}
function fmtRefInterceptor(e){try{if(String(e.method).toLowerCase()===`post`&&/\/auth\/register$/.test(e.url||``)){let r=localStorage.getItem(`fmt_ref`);r&&!(e.data&&e.data.referral_code)&&(e.data=Object.assign({},e.data||{},{referral_code:r}))}}catch(_){}return e}
