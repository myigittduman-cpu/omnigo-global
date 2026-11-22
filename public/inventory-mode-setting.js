import {esc} from './ui.js';
import {inventoryModelDefinitions,inventoryModelDefinition} from './inventory-model-definitions.js';
import {managedInventoryMode,inventoryModeValue} from './inventory-management-model.js';
import {html,t} from './i18n.js';

export function inventoryModeSetting(mode='both',option=null,settings={mode}){
 const selected=inventoryModeValue(settings,option),models={...inventoryModelDefinitions,...(option?{[managedInventoryMode]:option}:{})};
 return `<label class="permission-section inventory-mode-setting"><span>Stok modeli</span><select data-inventory-mode aria-describedby="inventoryModelDescription">${Object.entries(models).map(([value,model])=>`<option value="${value}" ${selected===value?'selected':''}>${esc(model.label)}</option>`).join('')}</select><small id="inventoryModelDescription">${esc(selected===managedInventoryMode?option.description:inventoryModelDefinition(mode).description)}</small></label>`;
}

export function inventoryModeChoices(configuration){
 const settings=configuration.inventory||{mode:'marketplace'},option=configuration.inventoryManagement;
 const selected=inventoryModeValue(settings,option),models={...inventoryModelDefinitions,...(option?{[managedInventoryMode]:option}:{})};
 return html`<fieldset class="permission-card inventory-mode-choices" aria-label="Stok modeli">
  ${Object.entries(models).map(([value,model])=>html`<label class="inventory-mode-choice">
   <input type="radio" name="inventoryModel" data-inventory-mode value="${esc(value)}" ${selected===value?'checked':''}>
   <span><strong>${esc(t(model.label))}</strong><small>${esc(t(model.summary||model.description))}</small></span>
  </label>`).join('')}
 </fieldset>`;
}
