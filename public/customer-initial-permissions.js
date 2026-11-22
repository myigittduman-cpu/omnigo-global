import {html,t} from './i18n.js';
import {inventoryModeSetting} from './inventory-mode-setting.js';
import {inventoryModelDefinition} from './inventory-model-definitions.js';
import {inventoryModeSelection} from './inventory-management-model.js';
import {setupOptionInfo} from './customer-onboarding-options.js';

export function bindInitialPermissions(form,template){
 const configuration=structuredClone(template.configuration);
 const inventory=form.querySelector('[data-initial-inventory]');
 const stockOption=configuration.inventoryManagement;
 const modelDetails=()=>configuration.inventory.marketplaceManagement&&stockOption
  ?stockOption:inventoryModelDefinition(configuration.inventory.mode);
 inventory.innerHTML=html`<div class="store-setup-model-choice">
  ${inventoryModeSetting(configuration.inventory.mode,stockOption,configuration.inventory)}
  ${setupOptionInfo('Stok modeli',modelDetails().description)}
 </div>`;
 const modelDescription=inventory.querySelector('#inventoryModelDescription');
 modelDescription.hidden=true;
 const updateModelInfo=()=>{
  const model=modelDetails();
  modelDescription.textContent=t(model.description);
  inventory.querySelector('[data-setup-info-title]').textContent=t(model.label);
  inventory.querySelector('[data-setup-info-description]').textContent=t(model.description);
 };
 updateModelInfo();
 inventory.addEventListener('change',event=>{
  if(!event.target.hasAttribute('data-inventory-mode'))return;
  configuration.inventory=inventoryModeSelection(event.target.value,stockOption);
  updateModelInfo();
 });
 return {value:()=>structuredClone(configuration)};
}
