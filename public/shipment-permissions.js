import {customerCan} from './customer-access.js';
import {shipmentDocumentPages} from './shipment-document-pages.js';

export function shipmentDocumentCapabilities(state,page){
 const can=action=>customerCan(state,page,action);
 const grants=state.data?.workspaceAccess?.grants?.[page]??state.data?.workspaceAccess?.grants?.shipments;
 const canDocuments=can('documents'),canUpload=canDocuments&&can('upload');
 return {canDocuments,canUpload,canReplaceDocuments:canUpload&&(!Object.hasOwn(grants||{},'replaceDocuments')||can('replaceDocuments')),
  canDeleteDocuments:canDocuments&&can('deleteDocuments')};
}
export function shipmentCapabilities(state){
 const grants=state.data?.workspaceAccess?.grants?.shipments;
 const can=action=>customerCan(state,'shipments',action);
 const canSelect=grants&&!Object.hasOwn(grants,'selectRows')?(can('status')||can('export')):can('selectRows');
 const canBulkStatus=can('status')&&canSelect&&(grants&&!Object.hasOwn(grants,'bulkStatus')?can('status'):can('bulkStatus'));
 const canUpload=can('upload'),canReplaceDocuments=canUpload&&(!Object.hasOwn(grants||{},'replaceDocuments')||can('replaceDocuments'));
 const canDeleteDocuments=can('documents')&&can('deleteDocuments');
 const documentPermissions=Object.fromEntries(Object.entries(shipmentDocumentPages).map(([page,{kind}])=>[kind,shipmentDocumentCapabilities(state,page)]));
 return {canSelect,canBulkStatus,readOnly:!canSelect&&!canBulkStatus&&!['create','edit','delete','status','invoice','documents','upload','export'].some(can),canCreate:can('create'),canEdit:can('edit'),canDelete:can('delete'),canConfirm:can('status'),canInvoice:can('invoice'),canDocuments:can('documents'),canUpload,canReplaceDocuments,canDeleteDocuments,canShare:can('export'),documentPermissions};
}
/** Removes only unavailable controls; table columns, layout and copy/filter controls are retained. */
export function applyShipmentCapabilities(root,model){
 const rules=[
  [model.canCreate||model.canEdit||model.canDelete,'[data-shipment-edit-mode]'],
  [model.canCreate,'[data-shipment-add]'],[model.canEdit,'[data-shipment-edit-row]'],[model.canDelete,'[data-shipment-delete]'],
  [model.canConfirm,'.shipment-status-editor'],[model.canBulkStatus,'[data-shipment-bulk-status]'],
  [model.canInvoice,'[data-shipment-check="invoiced"]'],
  [model.canDocuments,'[data-shipment-document]'],[model.canShare,'[data-shipment-export],[data-shipment-share],[data-shipment-print]'],
  [model.canSelect,'[data-shipment-select],[data-shipment-select-all]']
 ];
 for(const [allowed,selector] of rules)if(allowed===false)root.querySelectorAll(selector).forEach(el=>el.remove());
 for(const [kind,rights] of Object.entries(model.documentPermissions||{})){
  if(!rights.canDocuments)root.querySelectorAll('[data-shipment-document="'+kind+'"]').forEach(el=>el.remove());
 }
 if(model.documentPermissions&&!Object.values(model.documentPermissions).some(rights=>rights.canDocuments)){
  root.querySelectorAll('[data-shipment-document=download]').forEach(el=>el.remove());
 }
}
