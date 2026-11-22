/** File inputs bubble their own cancel event; this is not a request to close a dialog. */
export function isDialogCancel(event,dialog){return event.target===dialog;}
