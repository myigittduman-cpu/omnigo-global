(() => {
  const months = ['ocak','şubat','mart','nisan','mayıs','haziran','temmuz','ağustos','eylül','ekim','kasım','aralık'];
  const clean = value => String(value ?? '').replace(/\s+/gu,' ').trim();

  function day(value) {
    const text = clean(value);
    const numeric = /^(\d{1,2})[./](\d{1,2})[./](\d{4})$/u.exec(text);
    const named = /^(\d{1,2}) ([\p{L}]+) (\d{4})$/u.exec(text.toLocaleLowerCase('tr-TR'));
    if (!numeric && !named) throw new Error('Tarih biçimi değişti.');
    const date = Number((numeric ?? named)[1]);
    const month = numeric ? Number(numeric[2]) : months.indexOf(named[2])+1;
    const year = Number((numeric ?? named)[3]);
    const stamp = new Date(Date.UTC(year,month-1,date));
    if (stamp.getUTCFullYear()!==year || stamp.getUTCMonth()!==month-1 || stamp.getUTCDate()!==date) {
      throw new Error('Ödeme tarihi geçersiz.');
    }
    return stamp.toISOString().slice(0,10);
  }

  function cents(value) {
    const text = clean(value).replace(/^(?:₺\s*)|(?:\s*(?:TL|₺))$/gu,'');
    const match = /^([-−]?)(\d{1,12}|\d{1,3}(?:\.\d{3})+),(\d{2})$/u.exec(text)
      ?? /^([-−]?)(\d{1,12})\.(\d{2})$/u.exec(text);
    if (!match) throw new Error('Net ödeme tutarı okunamadı.');
    const amount = Number(match[2].replaceAll('.',''))*100+Number(match[3]);
    if (!Number.isSafeInteger(amount) || amount>100000000000) throw new Error('Net ödeme tutarı geçersiz.');
    return match[1] ? -amount : amount;
  }

  function today() {
    const parts = new Intl.DateTimeFormat('tr-TR',{
      timeZone:'Europe/Istanbul',year:'numeric',month:'2-digit',day:'2-digit',
    }).formatToParts(new Date());
    const at = type => parts.find(part=>part.type===type).value;
    return [at('year'),at('month'),at('day')].join('-');
  }

  function finalize(rows) {
    const seen = new Set();
    for (const row of rows) {
      if (seen.has(row.date)) throw new Error('Pazaryeri aynı ödeme tarihini birden fazla gösterdi.');
      seen.add(row.date);
    }
    return rows.filter(row=>row.date>=today()).sort((a,b)=>a.date.localeCompare(b.date));
  }

  globalThis.omnigoPayoutFormat = {clean,day,cents,finalize};
})();
