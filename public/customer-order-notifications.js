export const customerOrderOptions={
 deliveryAdded:['Şehir Dışı Teslimatlar: yeni sipariş',['deliveries'],'Yeni sipariş Şehir Dışı Teslimatlar listesine eklendiğinde bildirir.'],
 shipmentAdded:['Şehir Dışı Sevkiyat: yeni sipariş',['shipments'],'Yeni sipariş Şehir Dışı Sevkiyat tablosuna eklendiğinde bildirir.']
};

const views={
 client_delivery_added:'deliveries',client_delivery_cancelled:'deliveries',
 client_shipment_added:'shipments',client_shipment_cancelled:'shipments'
};

export function customerOrderEvent(item){
 const events=item?.events;
 return Array.isArray(events)&&events.length===1&&Object.hasOwn(views,events[0])?events[0]:null;
}
export const customerOrderView=item=>views[customerOrderEvent(item)]||null;
export const cancellationNotice=item=>item?.events?.includes('cancelled')||customerOrderEvent(item)?.endsWith('_cancelled');
