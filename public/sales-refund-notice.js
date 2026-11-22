import {t,text} from './i18n.js';
export function refundCoverageNote(data){
 if(data.sensitiveVisibility?.returns===false)return '';
 const names=[...new Set((data.sales||[]).filter(row=>row.refundsVerified===false).map(row=>row.channel).filter(name=>typeof name==='string'&&name.trim()))];
 return names.length?text`${names.join(', ')} için iade verileri henüz aktarılmadı.`:data.summary?.refundsVerified===false?t('Bazı pazaryerlerinin iade verileri henüz aktarılmadı.'):'';
}
