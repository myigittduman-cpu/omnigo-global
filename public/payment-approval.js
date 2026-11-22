export const paymentIsApproved=payment=>Boolean(payment?.paymentApproval?.at);

export const canApprovePayment=(payment,state)=>state?.user?.role==='admin'&&Number.isInteger(payment?.id)&&payment.status==='paid'&&!payment.integration&&!payment.archivedAt&&!paymentIsApproved(payment);
