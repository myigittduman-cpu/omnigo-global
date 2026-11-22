export function usesMobileIcons(navigator={}){
 return /Android|iPhone|iPad|iPod/i.test(navigator.userAgent||'')||(/Mac/i.test(navigator.platform||'')&&Number(navigator.maxTouchPoints)>1);
}
export function installationManifest(role,navigator){return `/${role}${usesMobileIcons(navigator)?'-mobile-v3':''}.webmanifest`;}
