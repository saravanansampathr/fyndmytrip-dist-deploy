/* ===== Accounts & inventory (spliced into the portal bundle by patch_accounts.py) ===== */
var fmtAcState={on:[`On`,`bg-emerald-100 text-emerald-800 border-emerald-300`],off:[`Off`,`bg-slate-100 text-slate-500 border-slate-300 line-through`],off_above:[`Off above`,`bg-amber-50 text-amber-700 border-amber-300`]};
function fmtAcShort(n){return String(n).replace(/\s*\(.*\)$/,``).replace(/ Supplier$/,``)}
function fmtAcSel(value,onChange,opts,w){return fmtAsH(`select`,{className:fmtAsIn,style:{maxWidth:w||`14rem`,width:`auto`},value:value??``,onChange:e=>onChange(e.target.value)},...opts.map(o=>(0,U.jsx)(`option`,{value:o[0],children:o[1]},o[0])))}
function fmtAcMoney(v,c){return fmtAsH(`span`,{className:`whitespace-nowrap`},fmtAsMoney(v,c))}
var fmtAcPlural={white_label:`White-label partners`,agent:`Agents (B2B)`,sub_agent:`Sub-agents`,api_partner:`API partners`};
function fmtAcChip(inv,st,onClick){let s=fmtAcState[st?.state]||fmtAcState.off;return fmtAsH(`button`,{key:inv.key,type:`button`,disabled:!onClick||st?.state===`off_above`,onClick,title:`${inv.name}: ${s[0]}${st?.source===`set`?` (set for this account)`:` (default)`}${st?.state===`off_above`?` (switched off for an account above)`:``}`,className:`m-0.5 rounded-full border px-2 py-0.5 text-xs ${s[1]} ${onClick&&st?.state!==`off_above`?`hover:opacity-80`:`cursor-default`}`,"data-inv":inv.key,"data-state":st?.state},fmtAcShort(inv.name))}
function fmtAcTile(label,value,sub){return fmtAsH(`div`,{className:`rounded-lg border border-slate-200 bg-white p-3`},fmtAsH(`p`,{className:`text-xs text-slate-500`},label),fmtAsH(`p`,{className:`text-xl font-bold text-brand-navy`},value),sub&&fmtAsH(`p`,{className:`text-xs text-slate-500`},sub))}
function FmtAccountsPage(){
kY(`Accounts & inventory`);
let[f,setF]=(0,x.useState)({type:``,status:``,q:``,days:30}),[sel,setSel]=(0,x.useState)([]),[open,setOpen]=(0,x.useState)(null),[m,setM]=(0,x.useState)(null),[bulk,setBulk]=(0,x.useState)({key:``,value:`on`});
let lq=zi({queryKey:[`fmt-accounts`,f],queryFn:async()=>(await K.get(`/admin/accounts`,{params:Object.fromEntries(Object.entries(f).filter(([,v])=>v!==``))})).data});
let d=lq.data,meta=d?.meta,rows=d?.data||[],cat=meta?.catalog||[];
let set=(k,v)=>{setF(p=>({...p,[k]:v}));setSel([])};
async function act(fn,ok){setM(null);try{await fn();await lq.refetch();ok&&setM({ok:!0,text:ok})}catch(e){setM({ok:!1,text:q(e)})}}
let toggle=(r,inv)=>act(()=>K.put(`/admin/accounts/${r.id}`,{inventories:{[inv.key]:!r.inventories[inv.key]?.allowed}}),`${fmtAcShort(inv.name)} ${r.inventories[inv.key]?.allowed?`switched off`:`switched on`} for ${r.name}.`);
let toAll=(inv,on)=>{let ids=rows.filter(r=>r.can_manage).map(r=>r.id);if(!ids.length||!confirm(`${on?`Give`:`Take`} ${fmtAcShort(inv.name)} ${on?`to`:`from`} all ${ids.length} account(s) listed?`))return;act(()=>K.post(`/admin/accounts/bulk`,{tenant_ids:ids,inventories:{[inv.key]:on}}),`Done for ${ids.length} account(s).`)};
let applyBulk=()=>{if(!bulk.key||!sel.length)return;let v=bulk.value===`on`?!0:bulk.value===`off`?!1:null;act(()=>K.post(`/admin/accounts/bulk`,{tenant_ids:sel,inventories:{[bulk.key]:v}}),`Updated ${sel.length} account(s).`).then(()=>setSel([]))};
let tsel=id=>setSel(s=>s.includes(id)?s.filter(v=>v!==id):[...s,id]);
let tot=(meta?.summary||[]).reduce((a,s)=>({accounts:a.accounts+s.accounts,bookings:a.bookings+s.bookings,sales:a.sales+s.sales}),{accounts:0,bookings:0,sales:0});
return fmtAsH(`div`,{className:`mx-auto max-w-7xl space-y-5`,"data-testid":`accounts-page`},
fmtAsH(`div`,{},fmtAsH(`h1`,{className:`text-2xl font-bold text-brand-navy`},`Accounts & inventory`),fmtAsH(`p`,{className:`text-sm text-slate-600`},`FyndMyTrip is the master account. White-label partners, agents and API partners sell only the inventories and services switched on for them here. A partner's own accounts never get more than the partner has.`)),
!d?fmtAsH(`p`,{className:`text-sm text-slate-500`},lq.error?q(lq.error):`Loading…`):fmtAsH(`div`,{className:`space-y-5`},
fmtAsH(`div`,{className:`grid gap-3 sm:grid-cols-5`},fmtAcTile(`All accounts`,tot.accounts,`${tot.bookings} bookings · ${fmtAsMoney(tot.sales)}`),...meta.summary.map(s=>fmtAcTile(fmtAcPlural[s.type]||s.label,s.accounts,`${s.active} active · ${s.bookings} bookings · ${fmtAsMoney(s.sales)}`))),
fmtP2Row(fmtAsH(`span`,{className:`text-sm text-slate-600`},`Sales and bookings for the last`),fmtAcSel(String(f.days),v=>set(`days`,Number(v)),[[`7`,`7 days`],[`30`,`30 days`],[`90`,`90 days`],[`365`,`12 months`]],`9rem`),fmtAsMsg(m)),
meta.can_set_default&&fmtP2Card(fmtP2Title(`New accounts`),fmtAsH(`div`,{className:`flex flex-wrap gap-4 text-sm`},
fmtAsH(`label`,{className:`flex items-center gap-2`},fmtAsH(`input`,{type:`radio`,name:`fmt-def`,checked:meta.default_access===`all`,onChange:()=>act(()=>K.put(`/admin/accounts/default-access`,{default_access:`all`}),`Accounts with nothing set now get every inventory.`)}),`Get every inventory unless you switch it off`),
fmtAsH(`label`,{className:`flex items-center gap-2`},fmtAsH(`input`,{type:`radio`,name:`fmt-def`,checked:meta.default_access===`none`,onChange:()=>confirm(`Accounts with nothing set will lose every inventory until you assign one. Continue?`)&&act(()=>K.put(`/admin/accounts/default-access`,{default_access:`none`}),`Accounts with nothing set now get nothing until you assign it.`)}),`Get nothing until you assign it`)),
fmtP2Note(`This applies to every inventory you have not switched on or off for an account yourself. An account's own supplier contracts (its own API keys) always stay its own.`)),
fmtP2Card(fmtP2Title(`Inventories`),fmtP2Table(meta.inventory_usage,[[r=>r.name,`Inventory`],[r=>(r.modules||[]).map(k=>fmtAsSvc[k]||k).join(`, `),`Services`],[`accounts_with_access`,`Accounts with it`],[`accounts_without`,`Without`],[`bookings`,`Bookings`],[r=>fmtAcMoney(r.sales),`Sales`],[r=>r.paused?fmtAsH(`span`,{className:`text-red-600 font-medium`},`Paused (errors)`):`OK`,`Supplier`]],
meta.can_assign&&(r=>fmtAsH(`div`,{className:`flex gap-1`},fmtAsH(J,{variant:`secondary`,onClick:()=>toAll(r,!0)},`Give to all`),fmtAsH(J,{variant:`secondary`,onClick:()=>toAll(r,!1)},`Take from all`))))),
fmtP2Card(fmtP2Title(`Accounts`),fmtP2Row(fmtAcSel(f.type,v=>set(`type`,v),[[``,`Every type`],...meta.types.map(t=>[t.value,t.label])],`12rem`),fmtAcSel(f.status,v=>set(`status`,v),[[``,`Any status`],[`active`,`Active`],[`pending`,`Pending`],[`suspended`,`Suspended`]],`10rem`),fmtAsH(`input`,{className:fmtAsIn,style:{maxWidth:`16rem`},placeholder:`Search name or domain`,value:f.q,onChange:e=>set(`q`,e.target.value)})),
fmtP2Note(`Click an inventory to switch it on or off for that account. Amber means it is switched off for an account above (the partner).`),
sel.length>0&&fmtAsH(`div`,{className:`flex flex-wrap items-center gap-2 rounded-md bg-brand-teal/10 p-2 text-sm`},`${sel.length} selected:`,fmtAcSel(bulk.key,v=>setBulk(b=>({...b,key:v})),[[``,`Choose inventory`],...cat.map(i=>[i.key,fmtAcShort(i.name)])],`14rem`),fmtAcSel(bulk.value,v=>setBulk(b=>({...b,value:v})),[[`on`,`Switch on`],[`off`,`Switch off`],[`default`,`Back to default`]],`10rem`),fmtAsH(J,{onClick:applyBulk,disabled:!bulk.key},`Apply`),fmtAsH(J,{variant:`secondary`,onClick:()=>setSel([])},`Clear`)),
fmtP2Table(rows,[
[r=>r.can_manage?fmtAsH(`input`,{type:`checkbox`,checked:sel.includes(r.id),onChange:()=>tsel(r.id),"aria-label":`Select ${r.name}`}):null,``],
[r=>fmtAsH(`div`,{},fmtAsH(`p`,{className:`font-medium text-brand-navy`},r.name),fmtAsH(`p`,{className:`text-xs text-slate-500`},r.type_label+(r.parent&&r.parent.type!==`master`?` · under ${r.parent.name}`:``)),r.domain&&fmtAsH(`p`,{className:`text-xs text-slate-500`},r.domain+(r.dns_status===`verified`?` ✓`:``))),`Account`],
[r=>fmtAsH(`span`,{className:r.status===`active`?`text-emerald-700`:`text-amber-700`},fmtP2Label(r.status)),`Status`],
[r=>fmtAsH(`div`,{className:`max-w-md`},...cat.map(inv=>fmtAcChip(inv,r.inventories[inv.key],r.can_manage?()=>toggle(r,inv):null))),`Inventories`],
[r=>r.services.length===9?`All`:r.services.map(k=>fmtAsSvc[k]||k).join(`, `)||`None`,`Services`],
[`users`,`Users`],[`bookings`,`Bookings`],[r=>fmtAcMoney(r.sales),`Sales`],
[r=>r.network_bookings!==r.bookings?`${r.network_bookings} · ${fmtAsMoney(r.network_sales)}`:`–`,`With accounts below`],
[r=>r.last_booking_at?new Date(r.last_booking_at.replace(` `,`T`)).toLocaleDateString():`–`,`Last booking`]],
r=>fmtAsH(J,{variant:`secondary`,onClick:()=>setOpen(open===r.id?null:r.id)},open===r.id?`Close`:`Manage`))),
open&&fmtAsH(FmtAccountDetail,{key:open,id:open,days:f.days,catalog:cat,onSaved:()=>lq.refetch()})))}
function FmtAccountDetail({id,days,catalog,onSaved}){
let dq=zi({queryKey:[`fmt-account`,id,days],queryFn:async()=>(await K.get(`/admin/accounts/${id}`,{params:{days}})).data.data});
let[ed,setEd]=(0,x.useState)(null),[m,setM]=(0,x.useState)(null);
let a=dq.data;
(0,x.useEffect)(()=>{a&&setEd({inv:Object.fromEntries(catalog.map(i=>[i.key,a.inventories[i.key]?.source===`set`?(a.inventories[i.key].state===`off`?`off`:`on`):`default`])),services:a.services_set||null,status:a.status})},[a]);
if(!a||!ed)return fmtP2Card(fmtAsH(`p`,{className:`text-sm text-slate-500`},dq.error?q(dq.error):`Loading…`));
let all=Object.keys(fmtAsSvc),svc=ed.services||all;
let tsvc=k=>setEd(e=>{let c=e.services||all;c=c.includes(k)?c.filter(v=>v!==k):[...c,k];return{...e,services:c}});
let save=fmtP2Save(async()=>{let inv={};catalog.forEach(i=>{inv[i.key]=ed.inv[i.key]===`on`?!0:ed.inv[i.key]===`off`?!1:null});await K.put(`/admin/accounts/${id}`,{inventories:inv,services:ed.services&&ed.services.length===all.length?null:ed.services,status:ed.status});await dq.refetch();onSaved&&onSaved()},setM,`Saved. Applies to this account's next search.`);
return fmtAsH(`div`,{"data-testid":`account-detail`},fmtP2Card(fmtAsH(`div`,{className:`flex items-center justify-between`},fmtP2Title(`${a.name} · ${a.type_label}`),a.domain&&fmtAsH(`span`,{className:`text-sm text-slate-500`},a.domain)),
!a.can_manage&&fmtP2Note(`You can see this account but not change it.`),
fmtAsH(`div`,{className:`grid gap-4 lg:grid-cols-2`},
fmtAsH(`div`,{className:`space-y-2`},fmtAsH(`p`,{className:`text-sm font-semibold`},`Inventories`),...catalog.map(i=>fmtAsH(`div`,{key:i.key,"data-inv-row":i.key,className:`flex items-center justify-between gap-2 border-b border-slate-100 py-1 text-sm`},
fmtAsH(`div`,{},fmtAsH(`p`,{},i.name),fmtAsH(`p`,{className:`text-xs text-slate-500`},(i.modules||[]).map(k=>fmtAsSvc[k]||k).join(`, `)+` · now `+(fmtAcState[a.inventories[i.key]?.state]||[``])[0])),
fmtAcSel(ed.inv[i.key],v=>setEd(e=>({...e,inv:{...e.inv,[i.key]:v}})),[[`default`,`Default`],[`on`,`On`],[`off`,`Off`]],`8rem`)))),
fmtAsH(`div`,{className:`space-y-3`},fmtAsH(`p`,{className:`text-sm font-semibold`},`Services this account may sell`),fmtAsH(`div`,{className:`flex flex-wrap gap-3`},...all.map(k=>fmtAsH(`label`,{key:k,className:`flex items-center gap-1 text-sm`},fmtAsH(`input`,{type:`checkbox`,checked:svc.includes(k),onChange:()=>tsvc(k)}),fmtAsSvc[k]))),
fmtP2Note(`Effective now: ${a.services.map(k=>fmtAsSvc[k]||k).join(`, `)||`none`} (services switched off for an account above are removed too).`),
fmtAsField(`Account status`,fmtP2Sel(ed.status,v=>setEd(e=>({...e,status:v})),[[`active`,`Active`],[`suspended`,`Suspended (cannot log in or book)`],...(ed.status===`pending`?[[`pending`,`Pending`]]:[])],fmtAsIn+` max-w-xs`)))),
a.can_manage&&fmtP2Row(fmtAsH(J,{onClick:save},`Save account`),fmtAsMsg(m)),
fmtAsH(`div`,{className:`grid gap-4 lg:grid-cols-2`},fmtAsH(`div`,{},fmtAsH(`p`,{className:`mb-1 text-sm font-semibold`},`Sold by inventory (last ${a.days} days)`),fmtP2Table(a.by_inventory,[[`supplier_code`,`Inventory`],[`bookings`,`Bookings`],[r=>fmtAcMoney(r.sales),`Sales`]])),
fmtAsH(`div`,{},fmtAsH(`p`,{className:`mb-1 text-sm font-semibold`},`Sold by service`),fmtP2Table(a.by_service,[[r=>fmtAsSvc[r.module]||r.module,`Service`],[`bookings`,`Bookings`],[r=>fmtAcMoney(r.sales),`Sales`]]))),
fmtAsH(`div`,{},fmtAsH(`p`,{className:`mb-1 text-sm font-semibold`},`Latest bookings`),fmtP2Table(a.recent_bookings,[[`booking_code`,`Booking`],[r=>fmtAsSvc[r.module]||r.module,`Service`],[`supplier_code`,`Inventory`],[`status`,`Status`],[r=>fmtAcMoney(r.gross_amount,r.currency),`Amount`],[r=>new Date(String(r.created_at).replace(` `,`T`)).toLocaleString(),`When`]])),
fmtAsH(`div`,{className:`grid gap-4 lg:grid-cols-2`},fmtAsH(`div`,{},fmtAsH(`p`,{className:`mb-1 text-sm font-semibold`},`Accounts below`),fmtP2Table(a.sub_accounts,[[`name`,`Name`],[r=>fmtP2Label(r.type),`Type`],[`status`,`Status`]])),
fmtAsH(`div`,{},fmtAsH(`p`,{className:`mb-1 text-sm font-semibold`},`Logins`),fmtP2Table(Object.entries(a.users_by_role||{}).map(([k,v])=>({role:fmtAsRole[k]||fmtP2Label(k),count:v})),[[`role`,`Role`],[`count`,`People`]]),fmtP2Note(`Add or edit logins under Tenants.`)))))}
