/** Independent, read-only checks share one network round trip; setup and 401 keep their existing paths. */
export async function loadPortalIdentity(request){
 const [status,identity]=await Promise.allSettled([request('/status'),request('/me')]);
 if(status.status==='rejected')throw status.reason;
 if(status.value.needsSetup)return {status:status.value,identity:null};
 if(identity.status==='rejected'){
  if(identity.reason.status===401)return {status:status.value,identity:null};
  throw identity.reason;
 }
 return {status:status.value,identity:identity.value};
}
