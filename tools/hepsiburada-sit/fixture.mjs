import {randomUUID} from 'node:crypto';

// All identity/contact data is synthetic. Product codes are the official SIT documentation examples.
export function createFixture(merchantId, orderNumber) {
  return {
    OrderNumber: orderNumber,
    OrderDate: new Date().toISOString(),
    Customer: {CustomerId: randomUUID(), Name: 'OMNIGO SIT TEST'},
    DeliveryAddress: {
      AddressId: randomUUID(), Name: 'OMNIGO SIT TEST',
      AddressDetail: 'YALNIZ TEST - GERCEK TESLIMAT YAPILMAZ',
      City: 'İstanbul', District: 'Kuştepe', Town: 'Şişli', CountryCode: 'TR',
      Email: 'sit-test@example.invalid', PhoneNumber: '0000000000', AlternatePhoneNumber: '0000000000',
    },
    LineItems: [
      {Sku: 'HBV0000106NM0', MerchantSku: 'OMNIGO-SIT-A', MerchantId: merchantId, Quantity: 1,
        Price: {Amount: 120, Currency: 'TRY'}, Vat: 0, TotalPrice: {Amount: 120, Currency: 'TRY'}, CargoCompanyId: 1, DeliveryOptionId: 1},
      {Sku: 'HBV0000106NLG', MerchantSku: 'OMNIGO-SIT-B', MerchantId: merchantId, Quantity: 2,
        Price: {Amount: 75, Currency: 'TRY'}, Vat: 0, TotalPrice: {Amount: 150, Currency: 'TRY'}, CargoCompanyId: 1, DeliveryOptionId: 1},
    ],
  };
}

export function createListedFixture(merchantId, orderNumber, listing) {
  if (!listing?.isSalable || listing.availableStock < 2 || !Number.isFinite(listing.price) || listing.price <= 0
    || !listing.hepsiburadaSku || !listing.merchantSku || !listing.listingId) throw new Error('Uygun SIT ürünü yok.');
  const fixture = createFixture(merchantId, orderNumber);
  fixture.LineItems = [{...fixture.LineItems[0], Sku: listing.hepsiburadaSku, MerchantSku: listing.merchantSku,
    ListingId: listing.listingId, Quantity: 2, Price: {Amount: listing.price, Currency: 'TRY'},
    TotalPrice: {Amount: listing.price * 2, Currency: 'TRY'}}];
  return fixture;
}
