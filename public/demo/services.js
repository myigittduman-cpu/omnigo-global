// The public demo cannot register devices, alter sessions, poll production or share workspace events.
export function initializeBackground(){}
export function stopBackground(){}
export function backgroundActive(){return false;}
export function backgroundSound(){return false;}
export function bindBackgroundControls(){}
export function startLiveRefresh(){}
export function watchWebVersion(){}
export function startWorkspaceSync(){}
export function startOrderAlerts(){}
export function bindOrderAlertControls(){}
export function bindOrderSoundPreview(){}
export function bindShipmentSync(){}
export function browserLoginUpdateChecker(){return null;}
export function attachLoginUpdates(){}
export function attachClientSession(){}
