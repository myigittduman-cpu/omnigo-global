// Keep this presentation policy in sync with MarketplaceVisibility.php (covered by regression).
export const hiddenMarketplaces=['teknosa'];
export const marketplaceVisible=provider=>!hiddenMarketplaces.includes(String(provider??'').trim().toLowerCase().replace(/[ _]/g,''));
