import {validCalendarDate} from './calendar-date.js';

export const paymentIsApproved=payment=>Boolean(payment?.paymentApproval?.at);

export const paymentIsReported=payment=>['sent','paid'].includes(payment.status)&&!paymentIsApproved(payment);
export const paymentApprovable=payment=>Number.isSafeInteger(payment?.id)&&payment.id>0
 &&Number.isSafeInteger(payment.version)&&payment.version>0
 &&payment.status==='paid'&&validCalendarDate(payment.paidDate)&&Number.isSafeInteger(payment.actualCents)
 &&!payment.integration&&!payment.archivedAt&&!paymentIsApproved(payment);
export const canApprovePayment=(payment,state)=>state?.user?.role==='admin'&&paymentApprovable(payment);
export const paymentState=payment=>paymentIsApproved(payment)?'received':paymentIsReported(payment)?'sent':
 payment.verification==='confirmed'?'confirmed':'estimated';
