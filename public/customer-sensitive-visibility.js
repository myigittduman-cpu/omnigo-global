import {customerCan} from './customer-access.js';
import {salesTurnoverVisible} from './sales-turnover.js';
export function customerSensitiveVisibility(state,source={},page=state.view){
 return {cancellations:source.cancellations!==false&&customerCan(state,page,'viewCancelledRecords'),
  returns:source.returns!==false&&customerCan(state,page,'viewReturnedRecords'),
  afterCancellationTurnover:source.afterCancellationTurnover!==false&&customerCan(state,page,'viewAfterCancellationTurnover'),
  ...(source.turnover===false||!salesTurnoverVisible(state.data)?{turnover:false}:{})};
}
