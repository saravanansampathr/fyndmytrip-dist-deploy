var fmtAsIn=`w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-brand-teal focus:outline-none focus:ring-1 focus:ring-brand-teal`;
var fmtAsLbl=`block text-sm font-medium text-slate-700`;
var fmtAsSvc={flight:`Flights`,hotel:`Hotels`,bus:`Buses`,cab:`Cabs`,holiday:`Holidays`,insurance:`Insurance`,visa:`Visa`,cruise:`Cruises`,activity:`Activities`};
var fmtAsRole={super_admin:`Super admins`,staff:`Platform staff`,partner_admin:`White-label admins`,agent:`Agents`,sub_agent:`Sub-agents`,staff_user:`Account staff users`,customer:`Customers`};
function fmtAsH(t,p,...c){c=c.filter(v=>v!==null&&v!==void 0&&v!==!1);return c.length>1?(0,U.jsxs)(t,{...p,children:c}):(0,U.jsx)(t,{...p,children:c[0]})}
function fmtAsField(label,input,hint){return fmtAsH(`div`,{className:`space-y-1`},fmtAsH(`label`,{className:fmtAsLbl},label),input,hint&&fmtAsH(`p`,{className:`text-xs text-slate-500`},hint))}
function fmtAsMsg(m){return m?fmtAsH(`p`,{className:m.ok?`text-sm font-medium text-emerald-600`:`text-sm font-medium text-red-600`},m.text):null}
function fmtAsMoney(v,c){return (c||`INR`)+` `+Number(v||0).toLocaleString(`en-IN`,{minimumFractionDigits:2,maximumFractionDigits:2})}
/* ---------- Tax & invoices ---------- */
function FmtAsTax({data:d,options:o,onSaved}){
let[t,setT]=(0,x.useState)(()=>JSON.parse(JSON.stringify(d))),[m,setM]=(0,x.useState)(null),[busy,setBusy]=(0,x.useState)(!1);
let set=(path,v)=>setT(p=>{let n=JSON.parse(JSON.stringify(p)),o2=n,k=path.split(`.`);for(let i=0;i<k.length-1;i++)o2=o2[k[i]];o2[k[k.length-1]]=v;return n});
async function save(){setBusy(!0);setM(null);try{let{data:r}=await K.put(`/admin/settings/tax`,t);setT(r.data);onSaved&&onSaved();setM({ok:!0,text:`Saved. New invoices use these settings straight away.`})}catch(e){setM({ok:!1,text:q(e)})}finally{setBusy(!1)}}
return fmtAsH(`div`,{className:`space-y-5`},
fmtAsH(X,{className:`space-y-4`},fmtAsH(`h3`,{className:`text-base font-semibold text-brand-navy`},`GST`),
fmtAsH(`div`,{className:`grid gap-4 sm:grid-cols-3`},
fmtAsField(`Home state (your GST registration)`,fmtAsH(`select`,{className:fmtAsIn,value:t.home_state,onChange:e=>set(`home_state`,e.target.value)},...Object.entries(o.states).map(([k,v])=>(0,U.jsx)(`option`,{value:k,children:`${k} - ${v}`},k))),`Used until the company GSTIN is entered in Platform settings.`),
fmtAsField(`Invoice number prefix`,fmtAsH(`input`,{className:fmtAsIn,maxLength:5,value:t.invoice_prefix,onChange:e=>set(`invoice_prefix`,e.target.value.toUpperCase())}),`e.g. FMT → FMT2627-000001`),
fmtAsField(`Credit note prefix`,fmtAsH(`input`,{className:fmtAsIn,maxLength:5,value:t.credit_note_prefix,onChange:e=>set(`credit_note_prefix`,e.target.value.toUpperCase())}))),
fmtAsH(`table`,{className:`w-full text-sm`},fmtAsH(`thead`,{},fmtAsH(`tr`,{className:`text-left text-slate-500`},fmtAsH(`th`,{className:`py-1`},`Service`),fmtAsH(`th`,{},`GST % on your service fee`),fmtAsH(`th`,{},`SAC code`))),
fmtAsH(`tbody`,{},...o.services.map(s=>fmtAsH(`tr`,{key:s,className:`border-t border-slate-100`},fmtAsH(`td`,{className:`py-1 pr-3`},fmtAsSvc[s]||s),
fmtAsH(`td`,{className:`pr-3`},fmtAsH(`input`,{type:`number`,min:0,max:28,step:`0.01`,className:fmtAsIn,value:t.gst_rates[s]??``,onChange:e=>set(`gst_rates.${s}`,e.target.value)})),
fmtAsH(`td`,{},fmtAsH(`input`,{className:fmtAsIn,value:t.sac_codes[s]??``,onChange:e=>set(`sac_codes.${s}`,e.target.value)})))))),
fmtAsH(`p`,{className:`text-xs text-slate-500`},`Prices already include this GST; the invoice splits it into CGST + SGST (same state) or IGST.`)),
fmtAsH(X,{className:`space-y-4`},fmtAsH(`h3`,{className:`text-base font-semibold text-brand-navy`},`TCS on overseas holiday packages (section 206C(1G))`),
fmtAsH(`label`,{className:`flex items-center gap-2 text-sm`},fmtAsH(`input`,{type:`checkbox`,checked:!!t.tcs.enabled,onChange:e=>set(`tcs.enabled`,e.target.checked)}),`Collect TCS on international holiday packages`),
fmtAsH(`div`,{className:`grid gap-4 sm:grid-cols-3`},
fmtAsField(`Yearly threshold per PAN (₹)`,fmtAsH(`input`,{type:`number`,className:fmtAsIn,value:t.tcs.threshold,onChange:e=>set(`tcs.threshold`,e.target.value)})),
fmtAsField(`Rate up to threshold (%)`,fmtAsH(`input`,{type:`number`,step:`0.01`,className:fmtAsIn,value:t.tcs.rate_below,onChange:e=>set(`tcs.rate_below`,e.target.value)})),
fmtAsField(`Rate above threshold, or no PAN (%)`,fmtAsH(`input`,{type:`number`,step:`0.01`,className:fmtAsIn,value:t.tcs.rate_above,onChange:e=>set(`tcs.rate_above`,e.target.value)}))),
fmtAsH(`p`,{className:`text-xs text-slate-500`},`Please confirm the rates with your CA before switching this on. TCS is added to the price and shown on the invoice.`)),
fmtAsH(`div`,{className:`flex items-center gap-3`},fmtAsH(J,{onClick:save,disabled:busy},busy?`Saving…`:`Save tax settings`),fmtAsMsg(m)))}
/* ---------- Security & monitoring ---------- */
function FmtAsSecurity({data:d,monitoring:mo,options:o}){
let[s,setS]=(0,x.useState)(d),[mn,setMn]=(0,x.useState)({...mo,alert_emails:(mo.alert_emails||[]).join(`, `)}),[m,setM]=(0,x.useState)(null),[m2,setM2]=(0,x.useState)(null);
let st=zi({queryKey:[`fmt-system-status`],queryFn:async()=>(await K.get(`/admin/system-status`)).data.data});
async function save(){setM(null);try{let{data:r}=await K.put(`/admin/settings/security`,s);setS(r.data);setM({ok:!0,text:`Saved.`})}catch(e){setM({ok:!1,text:q(e)})}}
async function saveMon(){setM2(null);try{let em=mn.alert_emails.split(/[\s,;]+/).filter(Boolean);let{data:r}=await K.put(`/admin/settings/monitoring`,{alert_emails:em,backup_keep_days:Number(mn.backup_keep_days)});setMn({...r.data,alert_emails:r.data.alert_emails.join(`, `)});setM2({ok:!0,text:`Saved.`})}catch(e){setM2({ok:!1,text:q(e)})}}
let num=(k,label,hint)=>fmtAsField(label,fmtAsH(`input`,{type:`number`,className:fmtAsIn,value:s[k],onChange:e=>setS({...s,[k]:Number(e.target.value)})}),hint);
let chk=st.data?.checks||{};
let row=(label,c,extra)=>fmtAsH(`tr`,{className:`border-t border-slate-100`},fmtAsH(`td`,{className:`py-1 pr-3`},label),fmtAsH(`td`,{className:c?.ok?`text-emerald-600`:`text-red-600`},c?(c.ok?`OK`:`Needs attention`):`…`),fmtAsH(`td`,{className:`text-xs text-slate-500`},extra||c?.message||``));
return fmtAsH(`div`,{className:`space-y-5`},
fmtAsH(X,{className:`space-y-4`},fmtAsH(`h3`,{className:`text-base font-semibold text-brand-navy`},`Login security`),
fmtAsH(`div`,{className:`grid gap-4 sm:grid-cols-2`},num(`login_max_attempts`,`Wrong passwords before lock-out`),num(`login_lockout_minutes`,`Lock-out time (minutes)`),num(`staff_session_hours`,`Staff, agents and partners stay signed in for (hours)`),num(`customer_session_days`,`Customers stay signed in for (days)`)),
fmtAsH(`div`,{className:`space-y-1`},fmtAsH(`div`,{className:fmtAsLbl},`Ask these users to turn on two-factor login`),
fmtAsH(`div`,{className:`flex flex-wrap gap-4`},...o.roles.filter(r=>r!==`customer`).map(r=>fmtAsH(`label`,{key:r,className:`flex items-center gap-2 text-sm`},fmtAsH(`input`,{type:`checkbox`,checked:(s.require_2fa_roles||[]).includes(r),onChange:e=>setS({...s,require_2fa_roles:e.target.checked?[...(s.require_2fa_roles||[]),r]:(s.require_2fa_roles||[]).filter(x2=>x2!==r)})}),fmtAsRole[r]||r)))),
fmtAsH(`div`,{className:`flex items-center gap-3`},fmtAsH(J,{onClick:save},`Save login security`),fmtAsMsg(m))),
fmtAsH(X,{className:`space-y-4`},fmtAsH(`h3`,{className:`text-base font-semibold text-brand-navy`},`Alerts and backups`),
fmtAsField(`Send alerts to (emails, comma separated)`,fmtAsH(`input`,{className:fmtAsIn,value:mn.alert_emails,onChange:e=>setMn({...mn,alert_emails:e.target.value}),placeholder:`ops@yourcompany.com`}),`Paid bookings that failed, server errors and backup problems.`),
fmtAsField(`Keep daily backups for (days)`,fmtAsH(`input`,{type:`number`,className:fmtAsIn,value:mn.backup_keep_days,onChange:e=>setMn({...mn,backup_keep_days:e.target.value})})),
fmtAsH(`div`,{className:`flex items-center gap-3`},fmtAsH(J,{onClick:saveMon},`Save alerts`),fmtAsMsg(m2))),
fmtAsH(X,{className:`space-y-3`},fmtAsH(`h3`,{className:`text-base font-semibold text-brand-navy`},`System health`),
fmtAsH(`table`,{className:`w-full text-sm`},fmtAsH(`tbody`,{},row(`Database`,chk.database),row(`Cache`,chk.cache),row(`File storage`,chk.storage),row(`Scheduled jobs (cron)`,chk.scheduler,chk.scheduler?.last_run?`Last run ${new Date(chk.scheduler.last_run).toLocaleString()}`:null),row(`Daily backup`,chk.backup,chk.backup?.last?`Last ${new Date(chk.backup.last.at).toLocaleString()}${chk.backup.last.error?` — `+chk.backup.last.error:``}`:`No backup yet`))),
...((st.data?.warnings)||[]).map((w,i)=>fmtAsH(`p`,{key:i,className:`text-sm text-amber-700`},w)),
fmtAsH(`p`,{className:`text-xs text-slate-500`},`Uptime monitors can check `,fmtAsH(`code`,{},`/api/health`),`. Backups are saved in storage/app/backups on the server.`)))}
/* ---------- Messages ---------- */
function FmtAsMessages(){
let qc=vr(),tq=zi({queryKey:[`fmt-templates`],queryFn:async()=>(await K.get(`/admin/notification-templates`)).data.data}),
lq=zi({queryKey:[`fmt-notif-logs`],queryFn:async()=>(await K.get(`/admin/notification-logs`)).data.data}),
[sel,setSel]=(0,x.useState)(null),[f,setF]=(0,x.useState)(null),[m,setM]=(0,x.useState)(null),[to,setTo]=(0,x.useState)(``);
if(!tq.data)return fmtAsH(`p`,{className:`text-sm text-slate-500`},tq.error?q(tq.error):`Loading…`);
function open(ev,ch){let c=ev.channels[ch];setSel({event:ev.event,channel:ch,label:ev.label});setF({subject:c.subject||``,body:c.body||``,provider_template_id:c.provider_template_id||``,is_active:c.is_active!==!1});setM(null)}
async function save(){setM(null);try{await K.put(`/admin/notification-templates`,{event:sel.event,channel:sel.channel,...f});qc.invalidateQueries({queryKey:[`fmt-templates`]});setM({ok:!0,text:`Saved. The next message uses this text.`})}catch(e){setM({ok:!1,text:q(e)})}}
async function reset(){if(!confirm(`Go back to the default text?`))return;try{await K.delete(`/admin/notification-templates/${sel.event}/${sel.channel}`);await qc.invalidateQueries({queryKey:[`fmt-templates`]});setSel(null);setF(null)}catch(e){setM({ok:!1,text:q(e)})}}
async function test(){setM(null);try{let{data:r}=await K.post(`/admin/notification-templates/test`,{event:sel.event,channel:sel.channel,to});setM({ok:r.data.status!==`failed`,text:r.data.status===`failed`?`Not sent: ${r.data.error}`:`Sample sent via ${r.data.provider}.`});qc.invalidateQueries({queryKey:[`fmt-notif-logs`]})}catch(e){setM({ok:!1,text:q(e)})}}
let chName={email:`Email`,sms:`SMS`,whatsapp:`WhatsApp`};
return fmtAsH(`div`,{className:`grid gap-5 lg:grid-cols-5`},
fmtAsH(X,{className:`space-y-3 lg:col-span-2`},fmtAsH(`h3`,{className:`text-base font-semibold text-brand-navy`},`Messages`),
...tq.data.events.map(ev=>fmtAsH(`div`,{key:ev.event,className:`rounded-md border border-slate-200 p-3`},fmtAsH(`div`,{className:`text-sm font-medium text-slate-800`},ev.label),
fmtAsH(`div`,{className:`mt-2 flex flex-wrap gap-2`},...Object.keys(ev.channels).map(ch=>fmtAsH(`button`,{key:ch,type:`button`,onClick:()=>open(ev,ch),className:`rounded-full border px-3 py-1 text-xs ${sel&&sel.event===ev.event&&sel.channel===ch?`border-brand-teal bg-brand-teal text-white`:`border-slate-300 text-slate-700`}`},chName[ch]+(ev.channels[ch].is_edited?` • edited`:``)+(ev.channels[ch].is_active===!1?` (off)`:``))))))),
fmtAsH(`div`,{className:`space-y-5 lg:col-span-3`},
sel&&f?fmtAsH(X,{className:`space-y-3`},fmtAsH(`h3`,{className:`text-base font-semibold text-brand-navy`},`${sel.label} — ${chName[sel.channel]}`),
fmtAsH(`label`,{className:`flex items-center gap-2 text-sm`},fmtAsH(`input`,{type:`checkbox`,checked:f.is_active,onChange:e=>setF({...f,is_active:e.target.checked})}),`Send this message`),
sel.channel===`email`&&fmtAsField(`Subject`,fmtAsH(`input`,{className:fmtAsIn,value:f.subject,onChange:e=>setF({...f,subject:e.target.value})})),
fmtAsField(`Text`,fmtAsH(`textarea`,{className:fmtAsIn,rows:sel.channel===`email`?10:4,value:f.body,onChange:e=>setF({...f,body:e.target.value})})),
sel.channel!==`email`&&fmtAsField(sel.channel===`sms`?`DLT / MSG91 flow template ID`:`Approved WhatsApp template name or ID`,fmtAsH(`input`,{className:fmtAsIn,value:f.provider_template_id,onChange:e=>setF({...f,provider_template_id:e.target.value})}),`Messages to customers must use a template approved with your provider. Values are sent in the order the {{placeholders}} appear above.`),
fmtAsH(`details`,{className:`text-xs text-slate-600`},fmtAsH(`summary`,{className:`cursor-pointer`},`Placeholders you can use`),fmtAsH(`ul`,{className:`mt-1 grid gap-1 sm:grid-cols-2`},...Object.entries(tq.data.placeholders).map(([k,v])=>fmtAsH(`li`,{key:k},fmtAsH(`code`,{},`{{${k}}}`),` — ${v}`)))),
fmtAsH(`div`,{className:`flex flex-wrap items-center gap-2`},fmtAsH(J,{onClick:save},`Save`),fmtAsH(J,{variant:`secondary`,onClick:reset},`Use default text`),
fmtAsH(`input`,{className:`rounded-md border border-slate-300 px-2 py-1.5 text-sm`,placeholder:sel.channel===`email`?`you@example.com`:`Mobile number`,value:to,onChange:e=>setTo(e.target.value)}),fmtAsH(J,{variant:`secondary`,onClick:test,disabled:!to},`Send a sample`)),
fmtAsMsg(m)):fmtAsH(X,{},fmtAsH(`p`,{className:`text-sm text-slate-600`},`Pick a message and channel on the left to edit its text. SMS and WhatsApp are sent once a provider is connected in Notification gateways; email uses your mail settings until then.`)),
fmtAsH(X,{className:`space-y-2`},fmtAsH(`h3`,{className:`text-base font-semibold text-brand-navy`},`Recently sent`),
fmtAsH(`div`,{className:`overflow-x-auto`},fmtAsH(`table`,{className:`w-full text-xs`},fmtAsH(`thead`,{},fmtAsH(`tr`,{className:`text-left text-slate-500`},fmtAsH(`th`,{},`When`),fmtAsH(`th`,{},`Message`),fmtAsH(`th`,{},`To`),fmtAsH(`th`,{},`Status`))),
fmtAsH(`tbody`,{},...(lq.data||[]).slice(0,30).map(l=>fmtAsH(`tr`,{key:l.id,className:`border-t border-slate-100`},fmtAsH(`td`,{className:`py-1 pr-2 whitespace-nowrap`},new Date(l.created_at).toLocaleString()),fmtAsH(`td`,{className:`pr-2`},`${l.event} (${chName[l.channel]||l.channel})`),fmtAsH(`td`,{className:`pr-2`},l.recipient),fmtAsH(`td`,{className:l.status===`failed`?`text-red-600`:`text-emerald-600`,title:l.error||``},l.status+(l.error?` — `+l.error.slice(0,80):``))))))))))}
/* ---------- Website pages ---------- */
function FmtAsPages(){
let qc=vr(),pq=zi({queryKey:[`fmt-pages`],queryFn:async()=>(await K.get(`/admin/pages`)).data.data}),[f,setF]=(0,x.useState)(null),[m,setM]=(0,x.useState)(null);
async function save(pub){setM(null);try{await K.put(`/admin/pages/${f.slug}`,{title:f.title,body:f.body,meta_description:f.meta_description||null,is_published:pub});await qc.invalidateQueries({queryKey:[`fmt-pages`]});setF({...f,is_published:pub});setM({ok:!0,text:pub?`Published. Visitors now see this page.`:`Saved as draft. The website keeps its built-in page.`})}catch(e){setM({ok:!1,text:q(e)})}}
if(!pq.data)return fmtAsH(`p`,{className:`text-sm text-slate-500`},`Loading…`);
return fmtAsH(`div`,{className:`grid gap-5 lg:grid-cols-4`},
fmtAsH(X,{className:`space-y-2`},fmtAsH(`h3`,{className:`text-base font-semibold text-brand-navy`},`Pages`),...pq.data.map(p=>fmtAsH(`button`,{key:p.slug,type:`button`,onClick:()=>{setF({...p});setM(null)},className:`block w-full rounded-md border px-3 py-2 text-left text-sm ${f&&f.slug===p.slug?`border-brand-teal`:`border-slate-200`}`},p.title,fmtAsH(`span`,{className:`ml-2 text-xs ${p.is_published?`text-emerald-600`:`text-amber-600`}`},p.is_published?`Published`:`Not published`))),
fmtAsH(J,{variant:`secondary`,onClick:()=>{let s=prompt(`Page address (e.g. baggage-rules)`);s&&setF({slug:s.toLowerCase().replace(/[^a-z0-9]+/g,`-`).replace(/^-|-$/g,``),title:``,body:``,is_published:!1})}},`Add a page`)),
fmtAsH(`div`,{className:`lg:col-span-3`},f?fmtAsH(X,{className:`space-y-3`},
fmtAsH(`p`,{className:`text-xs text-slate-500`},`Shown at /${f.slug}. Starter text is a draft: have your lawyer check it, replace the [brackets], then publish.`),
fmtAsField(`Title`,fmtAsH(`input`,{className:fmtAsIn,value:f.title,onChange:e=>setF({...f,title:e.target.value})})),
fmtAsField(`Search engine description`,fmtAsH(`input`,{className:fmtAsIn,maxLength:300,value:f.meta_description||``,onChange:e=>setF({...f,meta_description:e.target.value})})),
fmtAsField(`Content (HTML: <h2>, <p>, <ul><li>, <a href>)`,fmtAsH(`textarea`,{className:fmtAsIn+` font-mono`,rows:18,value:f.body,onChange:e=>setF({...f,body:e.target.value})})),
fmtAsH(`div`,{className:`flex flex-wrap items-center gap-2`},fmtAsH(J,{onClick:()=>save(!0)},`Publish`),fmtAsH(J,{variant:`secondary`,onClick:()=>save(!1)},`Save as draft`),fmtAsMsg(m)),
fmtAsH(`div`,{className:`rounded-md border border-slate-200 p-4`},fmtAsH(`div`,{className:`mb-2 text-xs uppercase text-slate-400`},`Preview`),fmtAsH(`div`,{className:`fmt-cms space-y-3 text-sm text-slate-700`,dangerouslySetInnerHTML:{__html:f.body}}))):fmtAsH(X,{},fmtAsH(`p`,{className:`text-sm text-slate-600`},`Pick a page to edit. Payment gateways (Razorpay, Stripe) need published Terms, Privacy and Refund pages before they approve your account.`))))}
/* ---------- Failed bookings ---------- */
function FmtAsFailed(){
let qc=vr(),fq=zi({queryKey:[`fmt-failed`],queryFn:async()=>(await K.get(`/admin/failed-bookings`)).data.data,refetchInterval:60000}),[m,setM]=(0,x.useState)(null);
let kind={failed:`Supplier refused after payment`,stuck:`Paid, not ticketed after 30 min`,abandoned:`Paid at the gateway, customer did not return`};
async function issue(b){let t=prompt(`Ticket / PNR / voucher number(s) from the supplier, comma separated:`);if(!t)return;try{await K.post(`/admin/failed-bookings/${b.id}/issue`,{ticket_numbers:t.split(`,`).map(s=>s.trim()).filter(Boolean)});setM({ok:!0,text:`${b.booking_code} issued. The customer gets the confirmation.`});qc.invalidateQueries({queryKey:[`fmt-failed`]})}catch(e){setM({ok:!1,text:q(e)})}}
async function refund(b){if(!confirm(`Refund ${fmtAsMoney(b.amount,b.currency)} to the customer for ${b.booking_code}?`))return;try{let{data:r}=await K.post(`/admin/failed-bookings/${b.id}/refund`,{});setM({ok:!0,text:r.data.status===`completed`?`Refunded to the original payment method.`:`Refund started (${r.data.method}); finish it from the booking's refunds if it needs a bank transfer.`});qc.invalidateQueries({queryKey:[`fmt-failed`]})}catch(e){setM({ok:!1,text:q(e)})}}
return fmtAsH(X,{className:`space-y-3`},fmtAsH(`h3`,{className:`text-base font-semibold text-brand-navy`},`Paid bookings that need attention`),fmtAsMsg(m),
!fq.data?fmtAsH(`p`,{className:`text-sm text-slate-500`},fq.error?q(fq.error):`Loading…`):fq.data.length===0?fmtAsH(`p`,{className:`text-sm text-emerald-700`},`Nothing to fix right now.`):
fmtAsH(`div`,{className:`overflow-x-auto`},fmtAsH(`table`,{className:`w-full text-sm`},fmtAsH(`thead`,{},fmtAsH(`tr`,{className:`text-left text-slate-500`},fmtAsH(`th`,{},`Booking`),fmtAsH(`th`,{},`Problem`),fmtAsH(`th`,{},`Customer`),fmtAsH(`th`,{},`Amount`),fmtAsH(`th`,{},`Paid via`),fmtAsH(`th`,{}))),
fmtAsH(`tbody`,{},...fq.data.map(b=>fmtAsH(`tr`,{key:b.kind+b.id,className:`border-t border-slate-100 align-top`},
fmtAsH(`td`,{className:`py-2 pr-3`},fmtAsH(`div`,{className:`font-medium`},b.booking_code),fmtAsH(`div`,{className:`text-xs text-slate-500`},`${fmtAsSvc[b.module]||b.module} · ${b.account||``}`)),
fmtAsH(`td`,{className:`pr-3`},kind[b.kind]||b.kind,b.reason&&fmtAsH(`div`,{className:`text-xs text-slate-500`},b.reason)),
fmtAsH(`td`,{className:`pr-3 text-xs`},b.contact_email||``,fmtAsH(`br`,{}),b.contact_phone||``),
fmtAsH(`td`,{className:`pr-3 whitespace-nowrap`},fmtAsMoney(b.amount,b.currency)),fmtAsH(`td`,{className:`pr-3 text-xs`},b.paid_via),
fmtAsH(`td`,{className:`whitespace-nowrap`},fmtAsH(J,{onClick:()=>issue(b)},`Issue by hand`),` `,fmtAsH(J,{variant:`secondary`,onClick:()=>refund(b)},`Refund`))))))))}
/* ---------- GST register ---------- */
function FmtAsGst(){
let now=new Date(),y=now.getMonth()>=3?now.getFullYear():now.getFullYear()-1,fys=[0,1,2].map(i=>`${y-i}-${String(y-i+1).slice(-2)}`),[fy,setFy]=(0,x.useState)(fys[0]);
let gq=zi({queryKey:[`fmt-gst`,fy],queryFn:async()=>(await K.get(`/admin/gst-register`,{params:{financial_year:fy}})).data.data});
async function csv(){let r=await K.get(`/admin/gst-register`,{params:{financial_year:fy,format:`csv`},responseType:`blob`});let a=document.createElement(`a`);a.href=URL.createObjectURL(r.data);a.download=`gst-register-${fy}.csv`;a.click()}
let tot=(gq.data||[]).reduce((s,r)=>{let k=r.type===`Invoice`?1:-1;s.t+=k*r.taxable_value;s.g+=k*r.gst;s.c+=k*r.tcs;return s},{t:0,g:0,c:0});
return fmtAsH(X,{className:`space-y-3`},fmtAsH(`div`,{className:`flex flex-wrap items-center gap-3`},fmtAsH(`h3`,{className:`text-base font-semibold text-brand-navy`},`Invoices and credit notes`),
fmtAsH(`select`,{className:`rounded-md border border-slate-300 px-2 py-1.5 text-sm`,value:fy,onChange:e=>setFy(e.target.value)},...fys.map(v=>(0,U.jsx)(`option`,{value:v,children:`FY ${v}`},v))),fmtAsH(J,{variant:`secondary`,onClick:csv},`Download CSV`)),
fmtAsH(`p`,{className:`text-xs text-slate-600`},`Net for the year: taxable ${fmtAsMoney(tot.t)}, GST ${fmtAsMoney(tot.g)}, TCS ${fmtAsMoney(tot.c)}.`),
fmtAsH(`div`,{className:`overflow-x-auto`},fmtAsH(`table`,{className:`w-full text-xs`},fmtAsH(`thead`,{},fmtAsH(`tr`,{className:`text-left text-slate-500`},...[`Number`,`Type`,`Date`,`Booking`,`Buyer GSTIN`,`SAC`,`GST %`,`Taxable`,`GST`,`TCS`,`Total`].map(h=>fmtAsH(`th`,{key:h,className:`pr-2`},h)))),
fmtAsH(`tbody`,{},...(gq.data||[]).map(r=>fmtAsH(`tr`,{key:r.number,className:`border-t border-slate-100`},...[r.number,r.type+(r.against?` (${r.against})`:``),r.date,r.booking,r.buyer_gstin||`B2C`,r.sac,r.gst_rate,r.taxable_value.toFixed(2),r.gst.toFixed(2),r.tcs.toFixed(2),r.total.toFixed(2)].map((v,i)=>fmtAsH(`td`,{key:i,className:`py-1 pr-2`},String(v??``)))))))))}
/* ---------- the page ---------- */
function FmtAdminSettings(){
kY(`Admin settings`);
let u=Xs(e=>e.auth.user),staff=[`super_admin`,`staff`].includes(u?.role);
let tabs=staff?[[`tax`,`Tax & invoices`],[`messages`,`Messages`],[`pages`,`Website pages`],[`failed`,`Failed bookings`],[`gst`,`GST register`],[`security`,`Security & health`]]:[[`messages`,`Messages`],[`pages`,`Website pages`]];
let[tab,setTab]=(0,x.useState)(()=>{let h=(location.hash||``).slice(1);return tabs.some(t=>t[0]===h)?h:tabs[0][0]});
let sq=zi({queryKey:[`fmt-admin-settings`],queryFn:async()=>(await K.get(`/admin/settings`)).data.data,enabled:staff});
let go=t=>{setTab(t);history.replaceState(null,``,`#${t}`)};
let body=tab===`messages`?fmtAsH(FmtAsMessages,{}):tab===`pages`?fmtAsH(FmtAsPages,{}):tab===`failed`?fmtAsH(FmtAsFailed,{}):tab===`gst`?fmtAsH(FmtAsGst,{}):
!sq.data?fmtAsH(`p`,{className:`text-sm text-slate-500`},sq.error?q(sq.error):`Loading…`):tab===`tax`?fmtAsH(FmtAsTax,{key:`tax`,data:sq.data.tax,options:sq.data.options,onSaved:()=>sq.refetch()}):fmtAsH(FmtAsSecurity,{key:`sec`,data:sq.data.security,monitoring:sq.data.monitoring,options:sq.data.options});
return fmtAsH(`div`,{className:`space-y-5`,"data-testid":`admin-settings`},fmtAsH(`div`,{},fmtAsH(`h1`,{className:`text-2xl font-bold text-brand-navy`},`Admin settings`),fmtAsH(`p`,{className:`text-sm text-slate-500`},`Change taxes, messages, website pages and security here. Changes apply straight away; no code release needed.`)),
fmtAsH(`div`,{className:`flex flex-wrap gap-2 border-b border-slate-200 pb-2`},...tabs.map(([k,l])=>fmtAsH(`button`,{key:k,type:`button`,onClick:()=>go(k),className:`rounded-md px-3 py-1.5 text-sm ${tab===k?`bg-brand-navy text-white`:`text-slate-600 hover:bg-slate-100`}`},l))),body)}
/* ---------- my login security (2FA) ---------- */
function FmtAccountSecurity(){
kY(`Login security`);
let sq=zi({queryKey:[`fmt-2fa`],queryFn:async()=>(await K.get(`/auth/two-factor`)).data.data}),[setup,setSetup]=(0,x.useState)(null),[code,setCode]=(0,x.useState)(``),[codes,setCodes]=(0,x.useState)(null),[m,setM]=(0,x.useState)(null);
async function start(){setM(null);try{let{data:r}=await K.post(`/auth/two-factor/setup`);setSetup(r.data)}catch(e){setM({ok:!1,text:q(e)})}}
async function confirm2(){setM(null);try{let{data:r}=await K.post(`/auth/two-factor/confirm`,{code});setCodes(r.data.recovery_codes);setSetup(null);setCode(``);sq.refetch();try{sessionStorage.removeItem(`fmt_2fa_setup`)}catch(_){}}catch(e){setM({ok:!1,text:q(e)})}}
async function withPw(path){let pw=prompt(`Enter your password to continue:`);if(!pw)return;setM(null);try{let{data:r}=await K.post(`/auth/two-factor/${path}`,{password:pw});if(r.data?.recovery_codes)setCodes(r.data.recovery_codes);else{setCodes(null);setM({ok:!0,text:`Two-factor login is off.`})}sq.refetch()}catch(e){setM({ok:!1,text:q(e)})}}
let need=(()=>{try{return sessionStorage.getItem(`fmt_2fa_setup`)===`1`}catch(_){return!1}})();
return fmtAsH(`div`,{className:`mx-auto max-w-2xl space-y-5`},fmtAsH(`h1`,{className:`text-2xl font-bold text-brand-navy`},`Login security`),
need&&!sq.data?.enabled&&fmtAsH(`p`,{className:`rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800`},`Your administrator asks you to turn on two-factor login.`),
fmtAsH(X,{className:`space-y-3`},fmtAsH(`h3`,{className:`text-base font-semibold text-brand-navy`},`Two-factor login`),
fmtAsH(`p`,{className:`text-sm text-slate-600`},`After your password, you also enter a 6-digit code from an authenticator app (Google Authenticator, Microsoft Authenticator, Authy) on your phone.`),
sq.data?.enabled?fmtAsH(`div`,{className:`space-y-2`},fmtAsH(`p`,{className:`text-sm font-medium text-emerald-700`},`✓ On since ${new Date(sq.data.confirmed_at).toLocaleDateString()}`),fmtAsH(`div`,{className:`flex gap-2`},fmtAsH(J,{variant:`secondary`,onClick:()=>withPw(`recovery-codes`)},`New recovery codes`),fmtAsH(J,{variant:`secondary`,onClick:()=>withPw(`disable`)},`Turn off`))):
setup?fmtAsH(`div`,{className:`space-y-3`},fmtAsH(`p`,{className:`text-sm`},`1. Scan this code with your authenticator app:`),fmtAsH(`div`,{className:`inline-block rounded-md border border-slate-200 bg-white p-2`,dangerouslySetInnerHTML:{__html:setup.qr_svg}}),
fmtAsH(`p`,{className:`text-xs text-slate-500`},`Can't scan? Enter this key: `,fmtAsH(`code`,{className:`select-all`},setup.secret)),
fmtAsH(`p`,{className:`text-sm`},`2. Enter the 6-digit code it shows:`),fmtAsH(`div`,{className:`flex gap-2`},fmtAsH(`input`,{className:fmtAsIn+` max-w-[10rem]`,inputMode:`numeric`,value:code,onChange:e=>setCode(e.target.value)}),fmtAsH(J,{onClick:confirm2,disabled:code.length<6},`Turn on`))):
fmtAsH(J,{onClick:start},`Set up two-factor login`),
codes&&fmtAsH(`div`,{className:`rounded-md bg-slate-50 p-3`},fmtAsH(`p`,{className:`text-sm font-medium`},`Recovery codes — save them somewhere safe. Each works once if you lose your phone.`),fmtAsH(`pre`,{className:`mt-2 text-sm`},codes.join(`\n`))),
fmtAsMsg(m)))}
/* ---------- editable legal pages ---------- */
function FmtCmsPage({slug:s,fallback:F}){
let[p,setP]=(0,x.useState)(void 0);
(0,x.useEffect)(()=>{let live=!0;K.get(`/pages/${s}`).then(r=>live&&setP(r.data.data)).catch(()=>live&&setP(null));return()=>{live=!1}},[s]);
kY(p?.title||document.title,p?.meta_description);
if(p===void 0)return fmtAsH(`div`,{className:`mx-auto max-w-3xl py-10 text-sm text-slate-400`},`Loading…`);
if(!p)return(0,U.jsx)(F,{});
return fmtAsH(`div`,{className:`mx-auto max-w-3xl space-y-6`},fmtAsH(`div`,{},fmtAsH(`h1`,{className:`text-2xl font-bold text-brand-navy`},p.title),p.updated_at&&fmtAsH(`p`,{className:`mt-1 text-xs text-slate-400`},`Last updated ${p.updated_at}`)),
fmtAsH(`div`,{className:`fmt-cms space-y-4 text-sm leading-relaxed text-slate-700`,dangerouslySetInnerHTML:{__html:p.body}}))}
/* ---------- real card/UPI payments ---------- */
function fmtLoadScript(src){return new Promise((ok,bad)=>{if(document.querySelector(`script[src="${src}"]`))return ok();let s=document.createElement(`script`);s.src=src;s.onload=ok;s.onerror=()=>bad(Error(`Could not load the payment page. Check your connection.`));document.head.appendChild(s)})}
async function fmtGatewayCheckout(e){
let m=/\/(?:flights|hotels|bus|cabs|cruises|insurance|activities)\/bookings\/(\d+)\/pay$|\/(?:holiday|visa)-bookings\/(\d+)\/pay$/.exec(e.url||``);
if(String(e.method).toLowerCase()!==`post`||!m)return e;
let body=typeof e.data===`string`?JSON.parse(e.data||`{}`):Object.assign({},e.data||{});
if(body.razorpay_payment_id||body.fmt_checked)return e;
let id=m[1]||m[2],{data:r}=await K.post(`/payments/checkout`,{booking_id:Number(id),method:body.method,currency:body.currency||void 0});
let c=r.data;
if(c.mode===`razorpay`){await fmtLoadScript(`https://checkout.razorpay.com/v1/checkout.js`);
let res=await new Promise((ok,bad)=>{let rz=new window.Razorpay({key:c.key_id,order_id:c.order_id,amount:c.amount,currency:c.currency,name:c.name,description:c.description,prefill:c.prefill,theme:{color:`#0b2545`},handler:ok,modal:{ondismiss:()=>bad(Error(`Payment was cancelled. Your booking is still on hold — you can pay again.`))}});rz.on(`payment.failed`,x2=>{});rz.open()});
Object.assign(body,{razorpay_payment_id:res.razorpay_payment_id,razorpay_order_id:res.razorpay_order_id,razorpay_signature:res.razorpay_signature})}
else if(c.mode===`stripe`){let w=window.open(c.checkout_url,`fmt_pay`,`width=520,height=760`);if(!w){location.href=c.checkout_url;throw Error(`Opening the payment page…`)}
await new Promise((ok,bad)=>{let n=0,t=setInterval(async()=>{n++;try{let{data:s}=await K.get(`/payments/checkout/${c.transaction_id}/status`);if(s.data.paid){clearInterval(t);try{w.close()}catch(_){}ok()}else if(w.closed&&n>2){clearInterval(t);let{data:s2}=await K.get(`/payments/checkout/${c.transaction_id}/status`);s2.data.paid?ok():bad(Error(`Payment was not completed. Your booking is still on hold — you can pay again.`))}}catch(_){}if(n>400){clearInterval(t);bad(Error(`Payment timed out.`))}},3000)})}
body.fmt_checked=!0;e.data=body;return e}
