import {sameWorkspace,workspaceToken} from './workspace-context.js';

const pages={invoice:'shipmentInvoices'};

export async function openShipmentDocumentWorkspace(state,navigate,kind,row){
 if(!navigate||!Object.hasOwn(pages,kind))return false;
 const target={workspace:workspaceToken(state),kind,row};
 state.shipmentDocumentTarget=target;
 try{return await navigate(pages[kind]);}
 finally{if(state.shipmentDocumentTarget===target)delete state.shipmentDocumentTarget;}
}

export function takeShipmentDocumentTarget(state,kind){
 const target=state.shipmentDocumentTarget;
 if(target?.kind!==kind)return null;
 delete state.shipmentDocumentTarget;
 return target&&sameWorkspace(state,target.workspace)?target:null;
}
