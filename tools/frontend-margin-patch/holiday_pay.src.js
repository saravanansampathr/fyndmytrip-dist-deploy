function FmtHolidayPay({booking:b,onPaid:done,base:base=`holiday-bookings`,next:next=`Our holidays team will send your vouchers before travel.`,title:title=`Pay for your holiday`}){
let total=parseFloat(b.total_amount||b.gross_amount||0),
[wal,setWal]=(0,x.useState)(null),[useW,setUseW]=(0,x.useState)(!0),[amt,setAmt]=(0,x.useState)(null),
[pay,setPay]=(0,x.useState)(null),[meth,setMeth]=(0,x.useState)(null),[err,setErr]=(0,x.useState)(null),[busy,setBusy]=(0,x.useState)(!1);
(0,x.useEffect)(()=>{Up().then(w=>{setWal(w);let bal=parseFloat(w?.balance??0);setAmt(Math.min(bal,total).toFixed(2))}).catch(()=>setAmt(`0`))},[]);
if(b.status!==`held`)return(0,U.jsxs)(X,{className:`mt-3 space-y-1 border-emerald-200 bg-emerald-50`,"data-testid":`holiday-paid`,children:[(0,U.jsx)(`div`,{className:`text-sm font-semibold text-emerald-700`,children:`✓ Payment received — booking confirmed`}),(0,U.jsxs)(`div`,{className:`text-xs text-slate-600`,children:[`Booking ID `,b.booking_code||b.booking_reference.slice(0,8),` · Total paid ₹`,total.toFixed(2)]}),(0,U.jsx)(`div`,{className:`text-xs text-slate-500`,children:`${next} You can find this booking under My Trips.`})]});
let bal=parseFloat(wal?.balance??0),fromW=useW?Math.max(0,Math.min(parseFloat(amt||0)||0,bal,total)):0,rest=Math.max(0,total-fromW);
async function go(){setErr(null);setBusy(!0);try{let{data:r}=await K.post(`/${base}/${b.id}/confirm`,{wallet_amount:fromW.toFixed(2)});if(r.requires_payment){setPay(r.payment)}else done(r.data)}catch(e){setErr(q(e))}finally{setBusy(!1)}}
async function finish(){setErr(null);if(!meth){setErr(`Choose a payment method.`);return}setBusy(!0);try{let{data:r}=await K.post(`/${base}/${b.id}/pay`,{method:meth});done(r.data)}catch(e){setErr(q(e))}finally{setBusy(!1)}}
return(0,U.jsxs)(X,{className:`mt-3 space-y-3`,"data-testid":`holiday-pay`,children:[
(0,U.jsx)(`div`,{className:`text-sm font-semibold text-brand-navy`,children:title}),
(0,U.jsxs)(`div`,{className:`flex items-center justify-between text-sm`,children:[(0,U.jsx)(`span`,{className:`text-slate-500`,children:`Total payable`}),(0,U.jsxs)(`span`,{className:`font-bold text-brand-navy`,children:[`₹`,total.toFixed(2)]})]}),
pay?(0,U.jsxs)(U.Fragment,{children:[
(0,U.jsxs)(`p`,{className:`text-xs text-slate-500`,children:[`₹`,parseFloat(pay.wallet_amount_applied||0).toFixed(2),` from your wallet once payment is confirmed · ₹`,parseFloat(pay.amount).toFixed(2),` via payment gateway (transaction #`,pay.transaction_id,`)`]}),
(0,U.jsx)(`div`,{className:`grid grid-cols-2 gap-2`,children:(pay.available_methods??[]).map(m=>(0,U.jsx)(`button`,{type:`button`,onClick:()=>setMeth(m.method),className:`rounded-md border px-2 py-2 text-xs ${meth===m.method?`border-brand-teal bg-brand-teal/5 ring-1 ring-brand-teal`:`border-slate-300`}`,children:(Yne[m.method]?.icon??``)+` `+(bf[m.method]??m.method)},m.method))}),
(0,U.jsx)(`p`,{className:`text-xs text-slate-400`,children:`In production this step opens the payment gateway's hosted checkout and returns here once it calls back.`}),
(0,U.jsx)(J,{className:`w-full`,onClick:finish,disabled:busy,children:busy?`Confirming…`:`I've completed payment`})]}):(0,U.jsxs)(U.Fragment,{children:[
(0,U.jsxs)(`label`,{className:`flex items-center gap-2 text-sm`,children:[(0,U.jsx)(`input`,{type:`checkbox`,checked:useW,onChange:e=>setUseW(e.target.checked)}),`Use my wallet balance`,(0,U.jsxs)(`span`,{className:`text-xs text-slate-400`,children:[`(₹`,bal.toFixed(2),` available)`]})]}),
useW&&(0,U.jsx)(`input`,{type:`number`,min:0,step:`0.01`,value:amt??``,onChange:e=>setAmt(e.target.value),"aria-label":`Amount from wallet`,className:`w-full rounded-md border border-slate-300 px-2 py-1 text-sm`}),
(0,U.jsxs)(`div`,{className:`text-xs text-slate-500`,children:[`From wallet ₹`,fromW.toFixed(2),` · Via payment gateway ₹`,rest.toFixed(2)]}),
(0,U.jsx)(J,{className:`w-full`,onClick:go,disabled:busy||amt===null,children:busy?`Processing…`:rest>0?`Proceed to payment gateway`:`Pay ₹${total.toFixed(2)} from wallet`})]}),
(0,U.jsx)(Jp,{message:err})]})}

function FmtVisaApply({product:p}){
let user=Xs(e=>e.auth.user),[date,setDate]=(0,x.useState)(``),[apps,setApps]=(0,x.useState)([{name:``,passport_number:``}]),[bk,setBk]=(0,x.useState)(null),[err,setErr]=(0,x.useState)(null),[busy,setBusy]=(0,x.useState)(!1);
if(!user)return(0,U.jsxs)(`div`,{className:`mt-2 rounded-md bg-slate-50 p-3 text-xs text-slate-600`,"data-testid":`visa-login`,children:[`Please `,(0,U.jsx)(`a`,{href:`/login`,className:`font-medium text-brand-teal-dark underline`,children:`sign in`}),` to apply for this visa.`]});
if(bk)return(0,U.jsx)(FmtHolidayPay,{booking:bk,onPaid:setBk,base:`visa-bookings`,title:`Pay for your visa application`,next:`Our visa team will email you the document checklist and keep you posted until the visa is issued.`});
let set=(i,k,v)=>setApps(a=>a.map((r,j)=>j===i?{...r,[k]:v}:r));
async function go(){setErr(null);if(!date){setErr(`Choose your travel date.`);return}let list=apps.filter(a=>a.name.trim());if(!list.length||list.some(a=>!a.passport_number.trim())){setErr(`Enter each applicant's name and passport number.`);return}setBusy(!0);try{let{data:r}=await K.post(`/visa/${p.id}/applications`,{travel_date:date,applicants:list});setBk(r.data)}catch(e){setErr(q(e))}finally{setBusy(!1)}}
let est=parseFloat(p.sell_price||0)*Math.max(1,apps.filter(a=>a.name.trim()).length);
return(0,U.jsxs)(`div`,{className:`mt-2 space-y-2 border-t border-slate-100 pt-2`,"data-testid":`visa-apply`,children:[
(0,U.jsx)(`label`,{className:`block text-xs font-medium text-slate-600`,children:`Travel date`}),
(0,U.jsx)(`input`,{type:`date`,value:date,onChange:e=>setDate(e.target.value),"aria-label":`Travel date`,className:`w-full rounded-md border border-slate-300 px-2 py-1 text-sm`}),
(0,U.jsxs)(`div`,{className:`flex items-center justify-between`,children:[(0,U.jsx)(`span`,{className:`text-xs font-medium text-slate-600`,children:`Applicants`}),(0,U.jsx)(`button`,{type:`button`,onClick:()=>setApps(a=>[...a,{name:``,passport_number:``}]),className:`text-xs font-medium text-brand-teal-dark hover:underline`,children:`+ Add applicant`})]}),
apps.map((a,i)=>(0,U.jsxs)(`div`,{className:`flex gap-1`,children:[(0,U.jsx)(`input`,{placeholder:`Full name (as on passport)`,value:a.name,onChange:e=>set(i,`name`,e.target.value),className:`min-w-0 flex-1 rounded-md border border-slate-300 px-2 py-1 text-sm`}),(0,U.jsx)(`input`,{placeholder:`Passport no.`,value:a.passport_number,onChange:e=>set(i,`passport_number`,e.target.value.toUpperCase()),className:`w-28 rounded-md border border-slate-300 px-2 py-1 text-sm`}),apps.length>1&&(0,U.jsx)(`button`,{type:`button`,onClick:()=>setApps(x2=>x2.filter((r,j)=>j!==i)),className:`text-xs text-red-600`,children:`✕`})]},i)),
(0,U.jsxs)(`div`,{className:`flex items-center justify-between text-sm`,children:[(0,U.jsx)(`span`,{className:`text-slate-500`,children:`Estimated total`}),(0,U.jsxs)(`span`,{className:`font-bold text-brand-navy`,children:[`₹`,est.toFixed(2)]})]}),
(0,U.jsx)(J,{className:`w-full`,onClick:go,disabled:busy,children:busy?`Submitting…`:`Continue to payment`}),
(0,U.jsx)(Jp,{message:err})]})}
