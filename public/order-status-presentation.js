import {cancellationState} from './order-cancellation.js';
const normalize=value=>String(value||'').toLocaleLowerCase('tr').replaceAll('ı','i').replace(/[\s_-]+/g,' ').trim();
/** Badge colors only: source statuses and operational records are never changed. */
export function orderStatusClass(order){
 if(cancellationState(order))return 'cancelled';
 if(order.dispatchStatus)return ({pending:'waiting',shipped:'shipped',mixed:'waiting'})[order.dispatchStatus]||'';
 const values=[order.statusLabel,order.state,order.displayRowStatus,order.rowStatus].map(normalize);
 const has=(...states)=>values.some(value=>states.includes(value));
 if(has('cancelled','canceled','iptal edildi','iptal','refused','undelivered','teslim edilemedi'))return 'cancelled';
 // Prefer the displayed source label when delivery and completion are separate states.
 const groups={
  completed:['completed','closed','tamamlandi','sipariş tamamlandi'],
  delivered:['delivered','received','teslim edildi'],
  shipped:['shipped','dispatched','sevk edildi','kargolandi','kargoya verildi'],
  invoiced:['invoiced','faturasi kesildi','faturalandi'],
  waiting:['staging','shipping','waiting acceptance','waiting debit','waiting debit payment','pending','new','created','yeni','yeni sipariş','sevk bekliyor','hazirlaniyor','kargoya verilecek']
 };
 for(const value of values)for(const [tone,states]of Object.entries(groups))if(states.includes(value))return tone;
 return order.invoiced===true?'invoiced':'';
}
