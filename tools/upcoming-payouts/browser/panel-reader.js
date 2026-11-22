(() => {
  const {clean,day,cents,finalize} = globalThis.omnigoPayoutFormat;
  const text = el => clean(el?.innerText);
  const visible = (selector,root=document) => [...root.querySelectorAll(selector)].filter(el=>el.getClientRects().length);
  const hasLabel = (selector,label,root=document) => visible(selector,root).find(el=>text(el)===label);
  const abort = message => { throw new Error(message); };
  const wait = async (get,message,timeout=8000) => {
    const until=Date.now()+timeout;
    do {
      const result=get();
      if (result) return result;
      await new Promise(resolve=>setTimeout(resolve,125));
    } while (Date.now()<until);
    return abort(message);
  };

  const hosts = {
    pttavm:'https://merchant.pttavm.com/accounting/payoff/future-payments',
    pttavmIdentity:'https://merchant.pttavm.com/',
    idefix:'https://satici.idefix.com/finance/future-estimated-payments',
    koctas:'https://satisortagim.koctas.com.tr/pazaryerisatici/odeme-ve-finans/odemeler/gelecek-odemelerim',
  };
  const exactPage = (provider) => {
    if (location.origin+location.pathname!==hosts[provider]) abort('Satıcı panelinde oturum açın.');
  };

  async function pttavmIdentity(expected) {
    const cards=await wait(()=> {
      const list=visible('[class*="_store-card-body_"]');
      return list.length ? list : null;
    },'PTT AVM mağaza kimliği okunamadı.');
    if (cards.length!==1 || !hasLabel('p',expected,cards[0])) abort('PTT AVM mağazasını doğrulayın.');
    return true;
  }

  async function pttavm() {
    const cards=await wait(()=> {
      const container=document.querySelector('main');
      const count=/Toplam (\d+) ödemenizden (\d+) tanesini görüntülüyorsunuz/u.exec(text(container));
      if (!hasLabel('h3','Hakediş Ödemeleri') || !count) return null;
      const entries=visible('[class*="_payoff-item_"]',container);
      return Number(count[1])===Number(count[2]) && Number(count[2])===entries.length ? entries : null;
    },'PTT AVM ödeme listesi tamamlanmadı.');
    return cards.map(card=> {
      const lines=card.innerText.split('\n').map(clean).filter(Boolean);
      const label=lines.indexOf('Ödeme Günü:');
      const amounts=lines.filter(line=>line.startsWith('₺'));
      if (label<0 || amounts.length!==1) abort('PTT AVM ödeme kartı okunamadı.');
      return {date:day(lines[label+1]),amountCents:Math.max(0,cents(amounts[0]))};
    });
  }

  async function koctas(expected) {
    const table=await wait(()=> {
      if (!hasLabel('h4','Gelecek Ödemelerim')) return null;
      return visible('table').find(el=>text(el.tHead).includes('Tahmini Ödeme (Devreden Bakiye Dahil)'));
    },'Koçtaş gelecek ödemeler ekranı yüklenemedi.');
    if (!hasLabel('button',expected)) abort('Koçtaş mağazasını doğrulayın.');
    const headers=[...table.tHead.rows[0].cells].map(text);
    const dates=headers.indexOf('Ödeme Tarihi');
    const amounts=headers.indexOf('Tahmini Ödeme (Devreden Bakiye Dahil)');
    if (dates<0 || amounts<0) abort('Koçtaş net ödeme sütunu bulunamadı.');
    const rows=await wait(()=>{
      const entries=[...table.tBodies].flatMap(body=>[...body.rows]).filter(row=>row.getClientRects().length);
      const payments=entries.filter(row=>text(row.cells[dates])!=='Devreden Bakiye');
      return payments.length ? payments : null;
    },'Koçtaş ödeme listesi tamamlanmadı.');
    if (rows.length===1 && /^(?:Kayıt|Veri) bulunamadı\.?$/iu.test(text(rows[0]))) return [];
    return rows.map(row=>({
      date:day(text(row.cells[dates])),amountCents:Math.max(0,cents(text(row.cells[amounts]))),
    }));
  }

  function chooseOption(input) {
    if (input.getAttribute('aria-expanded')==='true') return;
    input.focus({preventScroll:true});
    input.dispatchEvent(new KeyboardEvent('keydown',{key:'ArrowDown',bubbles:true,cancelable:true}));
  }

  async function idefix(expected) {
    const heading=await wait(()=>hasLabel('h3','Gelecek Tahmini Ödemeler'),'idefix ödeme ekranı yüklenemedi.');
    const links=visible('a[href^="https://www.idefix.com/satici/"]');
    if (links.length!==1 || !links[0].pathname.endsWith('-'+expected)) abort('idefix mağazasını doğrulayın.');
    const amountLabel=await wait(()=>hasLabel('label','Tutar Bilgisi'),'idefix net tutar alanı bulunamadı.');
    const form=amountLabel.parentElement.parentElement;
    const total=()=> {
      const phrase='Toplam Gelecek Tahmini Ödeme:';
      const line=form.innerText.split('\n').map(clean).find(item=>item.startsWith(phrase));
      return cents(line?.slice(phrase.length));
    };
    const expectedCents=total();
    const field=visible('[role="combobox"]',form)[0];
    if (!field || !heading.isConnected) abort('idefix ödeme seçimi okunamadı.');
    const selected=()=>visible('button[aria-label^="Remove "]',form);
    for (const button of selected()) button.click();
    chooseOption(field);
    const menu=await wait(()=> {
      const list=document.getElementById(field.getAttribute('aria-controls'));
      return list?.getClientRects().length ? list : null;
    },'idefix ödeme tarihleri açılmadı.');
    const options=visible('[role="option"]',menu).map(text);
    if (!options.length && expectedCents===0 && /no options|kayıt bulunamadı|seçenek yok/iu.test(text(menu))) return [];
    if (!options.length || options.length>120) abort('idefix ödeme tarihleri eksik.');
    const rows=[];
    for (const label of options) {
      chooseOption(field);
      const option=await wait(()=>hasLabel('[role="option"]',label),'idefix ödeme tarihi açılamadı.');
      option.click();
      const amount=await wait(()=> {
        if (selected().length!==1 || selected()[0].getAttribute('aria-label')!=='Remove '+label) return null;
        const fragment=amountLabel.parentElement.innerText.split('\n').map(clean).find(line=>line.endsWith(' TL'));
        return fragment || null;
      },'idefix seçili tarihin tutarını vermedi.');
      rows.push({date:day(label),amountCents:cents(amount)});
      selected()[0].click();
    }
    if (rows.reduce((sum,row)=>sum+row.amountCents,0)!==expectedCents || total()!==expectedCents) {
      abort('idefix tarihli tutarlar panel toplamıyla eşleşmedi.');
    }
    return rows.map(row=>({...row,amountCents:Math.max(0,row.amountCents)}));
  }

  const readers = {pttavm,pttavmIdentity,idefix,koctas};
  globalThis.omnigoReadPayouts=async (provider,expected)=> {
    try {
      exactPage(provider);
      const result=await readers[provider](expected);
      return {ok:true,rows:Array.isArray(result) ? finalize(result) : null,
        observedAt:Math.floor(Date.now()/1000)};
    } catch (error) {
      return {ok:false,message:error instanceof Error ? error.message : 'Ödeme ekranı okunamadı.'};
    }
  };
})();
