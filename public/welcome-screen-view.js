export function welcomeScreenView(role = 'client') {
 const isAdmin = role === 'admin';
 const name = isAdmin ? 'Yönetici Portalı' : 'İş Ortağım';
 const roleClass = isAdmin ? ' omnigo-welcome-admin' : '';
 return `<div id="omnigoWelcome" class="omnigo-welcome${roleClass}" role="status" aria-label="Omnigo Global ${name} karşılama ekranı"><section class="omnigo-welcome-card"><svg class="omnigo-welcome-frame" viewBox="0 0 380 380" aria-hidden="true"><rect x="1" y="1" width="378" height="378" rx="19" pathLength="100"/></svg><div class="omnigo-welcome-logo"><img src="assets/omnigo-logo.png" alt="Omnigo Global · E-Ticaret Danışmanlığı" width="1672" height="941" fetchpriority="high"></div><h2 translate="no">${name}</h2><p class="omnigo-welcome-slogan" data-welcome-status>Çalışma alanınız hazırlanıyor…</p><div class="omnigo-welcome-indicator" aria-hidden="true"><div class="omnigo-welcome-dots"><span></span><span></span><span></span></div><svg class="omnigo-welcome-check" viewBox="0 0 32 32"><circle cx="16" cy="16" r="14"/><path d="m9 16 5 5 9-10"/></svg></div></section></div>`;
}
