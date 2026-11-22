import {esc} from './ui.js';

// Reuse the existing guarded customer switch; the list comes from the session.
export function workspaceCompany(company,{companies=[],canSwitch=false,selectLabel='Mağaza seç'}={}){
 if(!company)return '';
 const selectable=canSwitch&&companies.some(item=>Number(item.id)===Number(company.id));
 const selector=selectable?`<select class="workspace-company-select" data-workspace-company aria-label="${esc(selectLabel)}">${companies.map(item=>`<option value="${Number(item.id)}" ${Number(item.id)===Number(company.id)?'selected':''}>${esc(item.name)}</option>`).join('')}</select>`:'';
 return `<div class="workspace-company${selectable?' workspace-company--selectable':''}" aria-label="Çalışılan mağaza"><span class="workspace-company-label">Mağaza</span> <strong data-company-name="${Number(company.id)}" title="${esc(company.name)}">${esc(company.name)}</strong>${selector}${selectable?'<svg class="workspace-company-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>':''}</div>`;
}
