// The legacy 'classic' renderer remains available to callers that explicitly select it.
export const shipmentTableLayout='grouped';
export const shipmentLayouts={invoice:'Fatura düzeni',grouped:'Klasik düzen'};
export const isGroupedShipmentLayout=(layout=shipmentTableLayout)=>layout==='grouped';
export const shipmentLayoutFor=(preferences,company)=>preferences?.shipmentLayouts?.[company]==='grouped'?'grouped':'invoice';
