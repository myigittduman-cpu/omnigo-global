import {pageSnapshot} from './workspace-page-cache.js';
import {paintContent} from './content-paint.js';
import {preserveTablePosition,scrollTableStart} from './table-page-scroll.js';
import {syncManualDateFilters} from './manual-date-filters.js';
import {workspaceRange} from './workspace-date-range.js';
import {openDocumentPrint} from './document-print.js';
import {showAfterCancellationTurnover} from './analysis-metrics.js';
import {customerSensitiveVisibility} from './customer-sensitive-visibility.js';
import {customerTurnover,salesTurnoverVisible} from './sales-turnover.js';
import {customerCan} from './customer-access.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {openPaymentDetail} from './payment-details.js';
import {api} from './api.js';
import {esc,now,toast} from './ui.js';
import {workspaceToken,sameWorkspace} from './workspace-context.js';
import {analysisShell,analysisResult,analysisOutputActions} from './report-view.js';
import {analysisPeriodButtons,analysisPeriodRange,emptyMonthlyTotals,futureMonthReport} from './analysis-periods.js';
import {productPageData,reportProducts} from './report-products.js';
import {salesChart} from './report-chart.js';
import {calendarShell,paymentCalendar} from './payment-calendar.js';
import {currentPaymentForecast,upcomingPaymentFilename,upcomingPaymentModel} from './upcoming-payment-model.js';
import {refreshPaymentForecastCards} from './payment-summary-cards.js';
import {openPreparedPdfShare,openUpcomingPaymentShare} from './upcoming-payment-share.js';
import {openUpcomingPaymentEntry} from './upcoming-payment-entry.js';
import {openUpcomingPaymentAdjustment} from './upcoming-payment-adjustment.js';
import {manualPaymentForm} from './payment-form.js';
import {reviewUpcomingPayout} from './upcoming-payout-review.js';
import {openUpcomingPeriodPicker,upcomingPayments} from './upcoming-payments.js';
import {healthShell,healthResult} from './connection-health.js';
import {recordForm} from './forms.js';
import {openPaymentDay} from './payment-day-dialog.js';
import {paymentReceiptForm} from './payment-receipt-form.js';
import {receivedPaymentsDialog} from './received-payments-dialog.js';
import {downloadStatus,prepareReportSave,saveReportFile} from './report-download.js';
import {fileCenterRecorder} from './file-center-archive.js';
import {contentError} from './content-state.js';
import {customerWorkContent} from './customer-work.js';
import {isShipmentDocumentPage} from './shipment-document-pages.js';
import {bindShipmentDocumentTracking,shipmentDocumentTrackingContent} from './shipment-document-tracking.js';
import {isFinanceReportPage} from './finance-report-pages.js';
import {financeReportContent,bindFinanceReport} from './finance-report.js';
export function reportingContent(state){
 if(isShipmentDocumentPage(state.view))return shipmentDocumentTrackingContent(state.view,state.user.role==='admin');
 if(state.view==='overviewWork')return customerWorkContent(state);
 if(isFinanceReportPage(state.view))return financeReportContent(state);
 if(state.view==='payments')return calendarShell(state.user.role==='admin',state.data?.channels?.map(channel=>channel.name),state.data?.company,customerCan(state,'payments'));
 return state.view==='analytics'?analysisShell(state):healthShell(state);
}
export function bindReporting(app,state,{navigate}={}){
 const root=app.querySelector('[data-reporting]');if(!root)return;
 if(isFinanceReportPage(root.dataset.reporting)){bindFinanceReport(root,state);return;}
 if(isShipmentDocumentPage(root.dataset.reporting)){bindShipmentDocumentTracking(root,state);return;}
 const actionHost=app.querySelector('.page-heading-actions');
 const view=root.dataset.reporting,token=workspaceToken(state);let revision=0,report=null,busy=false,productPage=1;
 const recordFinance=fileCenterRecorder(state,token,'finance');
 const snapshot=pageSnapshot(state,view,['report']);report=null;const remembered={report:null};snapshot.restore(remembered);report=remembered.report;
 let paymentChannel=report?.channel||'all',changingPaymentChannel=false,changingPaymentPeriod=false;
 let upcomingPeriod=report?.periodView?report.from:'';
 const exportSelection={editing:false,excluded:new Set(),selectedMonths:[]};
 const valid=()=>root.isConnected&&sameWorkspace(state,token)&&state.view===view;
 const canExportUpcoming=()=>customerCan(state,'payments');
 const exportUpcomingCurrent=()=>valid()&&canExportUpcoming();
 const reportWithVisibility=()=>({...report,sensitiveVisibility:customerSensitiveVisibility(state,report.sensitiveVisibility)});
 const analyticsActionsHost=actionHost||root;
 const canExportAnalysis=()=>valid()&&customerCan(state,'analytics','export');
 const analysisForm=view==='analytics'?root.querySelector('.report-filter'):null;
 const analysisToolbar=root.querySelector('.analysis-period');
 const paintAnalysisPeriods=today=>paintContent(analysisToolbar,analysisPeriodButtons(state.reportFilters.period,today));
 const analysisRequestRange=(today=now())=>{
  const {period,from,to,channel}=state.reportFilters;
  return {...(analysisPeriodRange(period,today)??{from,to:to>today?today:to}),channel};
 };
 const matchesAnalysisReport=value=>{
  const selected=analysisRequestRange();
  return value&&['from','to','channel'].every(key=>value[key]===selected[key]);
 };
 if(analysisForm&&!matchesAnalysisReport(report))report=null;
 const setAnalysisRange=(range,{updateInputs=true,today=now()}={})=>{
  const filters=state.reportFilters;
  Object.assign(filters,range);
  if(updateInputs){
   for(const name of ['from','to','channel'])analysisForm.elements[name].value=filters[name];
   syncManualDateFilters(analysisForm);
  }
  paintAnalysisPeriods(today);
 };
 async function readAnalysis(){
  const today=now(),filters=state.reportFilters,range=analysisRequestRange(today);
  const preset=analysisPeriodRange(filters.period,today);
  if(preset&&(filters.from!==range.from||filters.to!==range.to)){
   setAnalysisRange(preset,{today,updateInputs:!analysisForm.contains(document.activeElement)});
  }else paintAnalysisPeriods(today);
  if(preset&&range.from>today){
   const future=futureMonthReport(range,state.data.company,state.data.channels);
   return state.data.turnoverDisplayBasis?{...future,turnoverDisplayBasis:state.data.turnoverDisplayBasis}:future;
  }
  const monthly=Boolean(preset&&filters.period!=='history');
  const data=await api('/analytics/report?'+new URLSearchParams({company:token.company,...range}));
  return monthly&&data.totals===null?{...data,totals:emptyMonthlyTotals}:data;
 }
 const received=receivedPaymentsDialog({getReport:()=>report,state,onSave:()=>refresh(true),onReportChanged:()=>display(),
  onMarketplaceChange:changePaymentChannel,current:valid});
 let paymentDayDialog;
 const error=message=>i18nHtml`${contentError(message)}<button class="btn" data-report-retry>Tekrar dene</button>`;
 const display=()=>{
  if(!valid()||!report)return;
  if(view==='analytics'){
   productPage=productPageData(report.products,productPage).page;
   const result=root.querySelector('.report-result');
   result.dataset.reportHasContent='true';
   paintContent(result,analysisResult(reportWithVisibility(),state.reportFilters.hidden));
   const downloads=analyticsActionsHost.querySelector('.report-downloads');
   if(!canExportAnalysis())downloads?.remove();
   else if(!downloads)analyticsActionsHost.insertAdjacentHTML('beforeend',analysisOutputActions());
  }
  if(view==='payments'){
   if(state.paymentDay&&(state.paymentDay<report.from||state.paymentDay>report.to))state.paymentDay='';
   paintContent(root,paymentCalendar(report,state.paymentDay||'',state.user.role==='admin',state.data?.company,upcomingPeriod,
    {...exportSelection,canExport:canExportUpcoming()}));
   received.refresh();
   paymentDayDialog?.refresh();
  }
  if(view==='connections'){
   const target=root.querySelector('.health-result');
   const expanded=new Set([...target.querySelectorAll('[data-health-detail][open]')].map(el=>el.dataset.healthDetail));
   if(paintContent(target,healthResult(report)))for(const el of target.querySelectorAll('[data-health-detail]'))el.open=expanded.has(el.dataset.healthDetail);
  }
 };
 async function refresh(manual=false,selectedPeriod=upcomingPeriod){
  if(busy||!valid())return;
  const seq=++revision;const target=view==='analytics'?root.querySelector('.report-result'):view==='connections'?root.querySelector('.health-result'):root;
  target.setAttribute('aria-busy','true');
  try{
   let data;
   if(view==='analytics')data=await readAnalysis();
   else if(view==='payments'){
    const {from,to}=workspaceRange(state);
    data=await api('/payments/calendar?'+new URLSearchParams({company:token.company,from,to,channel:paymentChannel,period:selectedPeriod}));
   }
   else data=await api('/admin/connection-health?company='+token.company);
   if(!valid()||revision!==seq)return;
   report=data;if(data.periodView)upcomingPeriod=data.from;
   snapshot.save({report});display();
  }catch(e){
   if(valid()&&revision===seq&&(view!=='payments'||!report)){
    report=null;analyticsActionsHost.querySelector('.report-downloads')?.remove();paintContent(target,error(e.message));
   }
   if(manual)throw e;
  }
  finally{if(valid()&&revision===seq)target.removeAttribute('aria-busy');}
 }
 async function changePaymentChannel(channel){
  if(view!=='payments'||changingPaymentChannel||!valid()||!report||channel===paymentChannel)return;
  if(channel!=='all'&&!report.marketplaces?.includes(channel))return;
  const previous=report;changingPaymentChannel=true;paymentChannel=channel;state.paymentDay='';
  try{await refresh(true);}
  catch(error){if(valid()){report=previous;paymentChannel=previous.channel||'all';display();toast(error.message);}}
  finally{changingPaymentChannel=false;}
 }
 root.reportRefresh=()=>{if(!changingPaymentPeriod)return refresh();};
 root.pageRefresh=async()=>{if(busy)throw new Error(i18nT('Rapor dosyasının hazırlanmasını bekleyin.'));await refresh(true);};
 analysisForm?.addEventListener('submit',event=>{
  event.preventDefault();if(busy)return;
  productPage=1;
  const fields=Object.fromEntries(new FormData(analysisForm)),{from,to,period}=state.reportFilters;
  setAnalysisRange({...fields,hidden:[],period:fields.from===from&&fields.to===to?period:null},{updateInputs:false});
  refresh();
 });
 analysisToolbar?.addEventListener('click',event=>{
  const button=event.target.closest('[data-analysis-period]');
  if(!button||busy||!valid())return;
  const period=button.dataset.analysisPeriod,today=now(),range=analysisPeriodRange(period,today);
  if(!range)return;
  productPage=1;setAnalysisRange({...range,period,hidden:[]},{today});
  [...analysisToolbar.querySelectorAll('[data-analysis-period]')].find(item=>item.dataset.analysisPeriod===period)?.focus({preventScroll:true});
  refresh();
 });
 root.querySelector('.report-filter [name=channel]')?.addEventListener('change',event=>event.target.form.requestSubmit());
 root.querySelector('.health-filter')?.addEventListener('submit',event=>{event.preventDefault();refresh();});
 root.querySelector('[name=search]')?.addEventListener('input',()=>{state.healthSearch=root.querySelector('[name=search]').value;display();});

 const upcomingFile=async (model,company,options)=>(await import('./upcoming-payment-export.js')).createUpcomingPaymentFile(model,company,options);
 const upcomingStatus=message=>{
  const area=root.querySelector('[data-upcoming-file-status]');
  if(valid()&&area)area.textContent=message;
 };
 async function downloadUpcomingPayment(model,company){
  busy=true;root.exportBusy=true;
  upcomingStatus(i18nT('Rapor dosyası hazırlanıyor…'));
  try{
   const destination=await prepareReportSave(upcomingPaymentFilename(model,company));
   if(!exportUpcomingCurrent())return;
   if(destination==='cancelled'){upcomingStatus(downloadStatus(destination));return;}
   const file=await upcomingFile(model,company);
   if(exportUpcomingCurrent())recordFinance?.(file);
   upcomingStatus(downloadStatus(await saveReportFile(file.blob,file.filename,exportUpcomingCurrent,globalThis,destination,{browserDownload:true})));
  }catch(error){upcomingStatus(error.message);}finally{busy=false;root.exportBusy=false;}
 }
 
 const renderUpcoming=(focus,periodChanged=false)=>{
  const panel=root.querySelector('.upcoming-payments');
  if(!panel)return;
  const restorePosition=preserveTablePosition(root);
  const model=currentPaymentForecast(report,upcomingPeriod,exportSelection.selectedMonths);
  panel.outerHTML=upcomingPayments(report.upcoming,report.channel,report.marketplaces,state.data?.company,upcomingPeriod,
   {...exportSelection,canManage:state.user.role==='admin',canExport:canExportUpcoming(),model});
  refreshPaymentForecastCards(root,model);
  const currentPanel=root.querySelector('.upcoming-payments');
  restorePosition();
  if(periodChanged){
   currentPanel.querySelector('table')?.classList.add('upcoming-period-changed');
  }
  if(focus)focus(currentPanel)?.focus({preventScroll:true});
 };
 root.addEventListener('change',async event=>{
  if(event.target.matches('[data-upcoming-market-selection]')&&report?.upcoming&&exportSelection.editing&&valid()&&canExportUpcoming()&&!busy){
   const market=event.target.value;
   if(event.target.checked)exportSelection.excluded.delete(market);
   else exportSelection.excluded.add(market);
   renderUpcoming(panel=>[...panel.querySelectorAll('[data-upcoming-market-selection]')].find(input=>input.value===market));
   return;
  }
  if(event.target.matches('[data-payment-marketplace]')){
   const filter=event.target;filter.disabled=true;
   await changePaymentChannel(filter.value);
   if(filter.isConnected){filter.value=report?.channel||'all';filter.disabled=false;}
  }
  if(event.target.matches('[data-chart-channel]')&&report){state.reportFilters.hidden=[...root.querySelectorAll('[data-chart-channel]:not(:checked)')].map(el=>el.dataset.chartChannel);root.querySelector('[data-chart]').innerHTML=salesChart(report.series,state.reportFilters.hidden);}
 });
 const handleAction=async event=>{
  if(changingPaymentChannel||changingPaymentPeriod)return;
  if(event.target.closest('[data-paid-payments],[data-pending-payments]')&&view==='payments'&&report&&!busy){
   received.openNotice(event.target.closest('[data-pending-payments]')?'reported':'received');return;
  }
  const button=event.target.closest('button');if(!button||button.disabled||busy)return;
  if(button.hasAttribute('data-product-page')&&view==='analytics'&&report){
   productPage=productPageData(report.products,button.dataset.productPage).page;
   const panel=root.querySelector('[data-report-products]');
   const visible=reportWithVisibility();
   panel.innerHTML=reportProducts(report.products,{amounts:salesTurnoverVisible(visible),gross:customerTurnover(visible),neutral:visible.hideMarketplaceSalesCancellations,adjusted:visible.turnoverDisplayBasis==='after_cancellations',afterCancellation:customerTurnover(visible)&&showAfterCancellationTurnover(visible)});
   panel.querySelector('[aria-current="page"]')?.focus({preventScroll:true});
   scrollTableStart(panel,'.report-table-scroll');
   return;
  }
  if(button.hasAttribute('data-report-retry'))refresh();
  if(button.hasAttribute('data-payment-notice')&&view==='payments'&&report&&state.user.role==='admin'&&valid()){
   received.openNotice();return;
  }
  if(button.hasAttribute('data-upcoming-edit')&&view==='payments'&&report?.upcoming&&valid()&&canExportUpcoming()){
   exportSelection.editing=!exportSelection.editing;
   if(exportSelection.editing)exportSelection.excluded.clear();
   renderUpcoming(panel=>panel.querySelector('[data-upcoming-edit]'));
   return;
  }
  if(button.matches('[data-upcoming-period-picker],[data-upcoming-period-clear]')&&view==='payments'&&report?.upcoming&&valid()){
   const apply=months=>{
    if(!valid())return;
    exportSelection.selectedMonths=months;
    renderUpcoming(panel=>panel.querySelector('[data-upcoming-period-picker], [data-upcoming-period-clear]'),true);
    scrollTableStart(root,'.upcoming-payments-table-frame');
   };
   if(button.hasAttribute('data-upcoming-period-clear'))apply([]);
   else openUpcomingPeriodPicker({
    months:upcomingPaymentModel(report.upcoming,report.channel,report.marketplaces,upcomingPeriod).months,
    selectedMonths:exportSelection.selectedMonths,current:valid,onApply:apply,
   });
   return;
  }
  if(button.hasAttribute('data-upcoming-page')&&view==='payments'&&report?.upcoming&&valid()){
   const model=upcomingPaymentModel(report.upcoming,report.channel,report.marketplaces,upcomingPeriod);
   const index=Number(button.dataset.upcomingPage),page=model.periods[index];
   if(!page||index===model.page-1)return;
   changingPaymentPeriod=true;
   button.disabled=true;
   try{
    await refresh(true,page.start);
    root.querySelector('[data-upcoming-page][aria-current="page"]')?.focus({preventScroll:true});
    scrollTableStart(root,'.upcoming-payments-table-frame');
   }catch(error){if(valid())toast(error.message);}
   finally{changingPaymentPeriod=false;if(button.isConnected)button.disabled=false;}
   return;
  }
  if(button.hasAttribute('data-upcoming-review')&&view==='payments'&&state.user.role==='admin'&&valid()){
   const change=report?.upcoming?.pendingChanges?.find(item=>item.key===button.dataset.upcomingReview);
   const company=state.data?.company;
   if(!change||Number(company?.id)!==Number(token.company))return;
   button.disabled=true;
   try{await reviewUpcomingPayout(change,{company,current:valid,onSave:()=>refresh(true)});}
   finally{button.disabled=false;}
   return;
  }
  if(button.hasAttribute('data-upcoming-manual')&&view==='payments'&&state.user.role==='admin'&&valid()){
   const {manualChannels=[],today,sourceWarnings}=report?.upcoming||{};
   openUpcomingPaymentEntry({company:token.company,channels:manualChannels,today,warnings:sourceWarnings,current:valid,onSave:()=>refresh(true)});
   return;
  }
  if(button.hasAttribute('data-upcoming-record')&&view==='payments'&&state.user.role==='admin'&&valid()){
   const upcoming=report?.upcoming;
   const item=upcoming?.periodItems?.find(entry=>String(entry.id)===button.dataset.upcomingRecord);
   if(!item)return;
   const remove=button.hasAttribute('data-upcoming-remove');
   const record=item.source==='manual'?upcoming.manualRecords?.find(entry=>entry.id===item.id):null;
   const onSave=()=>refresh(true);
   if(record&&!remove)manualPaymentForm(record,state,onSave,{availableChannels:upcoming.manualChannels,warnings:upcoming.sourceWarnings});
   else openUpcomingPaymentAdjustment(item,{company:token.company,today:upcoming.today,remove,current:valid,onSave,record});
   return;
  }
  if(button.matches('[data-upcoming-share],[data-upcoming-download],[data-upcoming-print]')
     &&view==='payments'&&canExportUpcoming()&&report?.upcoming&&valid()){
   const company=state.data?.company;
   const model=upcomingPaymentModel(report.upcoming,report.channel,report.marketplaces,upcomingPeriod,exportSelection.excluded,exportSelection.selectedMonths);
   if(!model.dates.length)return;
   if(Number(company?.id)!==Number(token.company)){
    upcomingStatus(i18nT('Rapor firması belirlenemedi. Sayfayı yenileyin.'));
    return;
   }
   if(button.hasAttribute('data-upcoming-share'))openUpcomingPaymentShare({model,company,current:exportUpcomingCurrent,onFileReady:recordFinance});
   else if(button.hasAttribute('data-upcoming-print'))openDocumentPrint({current:exportUpcomingCurrent,createFile:async()=>{
    const file=await upcomingFile(model,company,{printLayout:true});
    if(exportUpcomingCurrent())recordFinance?.({blob:file.archiveBlob,filename:file.filename});
    return file;
   }});
   else await downloadUpcomingPayment(model,company);
   return;
  }
  if(button.hasAttribute('data-calendar-payment-selection')&&view==='payments'&&report)received.open('calendar');
  if(button.hasAttribute('data-payment-day')){
   const day=button.dataset.paymentDay;
   if(!day){state.paymentDay='';display();}
   else if(report)paymentDayDialog=openPaymentDay({day,report,getReport:()=>report,state,onSave:()=>refresh(true),current:valid,
    onPaymentNotice:back=>received.openNotice('reported',back)});
  }
  if(button.hasAttribute('data-health-page')){state.healthPage=Number(button.dataset.healthPage);refresh();}
  if(button.dataset.paymentDetail&&report){const record=[...report.items,...(report.undatedItems||[]),...(report.archivedItems||[])].find(p=>p.id===Number(button.dataset.paymentDetail));if(record)openPaymentDetail(record,{state,onSave:refresh,current:valid});}
  if(button.dataset.calendarEdit&&state.user.role==='admin'&&report){const record=[...report.items,...(report.undatedItems||[])].find(p=>p.id===Number(button.dataset.calendarEdit));if(record)recordForm('payments',record,state,refresh);}
  if(button.dataset.calendarReceipt&&state.user.role==='admin'&&report){const record=[...report.items,...(report.undatedItems||[])].find(p=>p.id===Number(button.dataset.calendarReceipt)&&!p.integration);if(record)paymentReceiptForm(record,state,refresh);}
  if(view==='analytics'&&button.matches('[data-report-print],[data-report-share],[data-export]')&&matchesAnalysisReport(report)&&canExportAnalysis()){
   const snapshot=reportWithVisibility();
   const visibility=JSON.stringify(snapshot.sensitiveVisibility);
   const current=()=>canExportAnalysis()&&matchesAnalysisReport(snapshot)&&report&&JSON.stringify(reportWithVisibility().sensitiveVisibility)===visibility;
   const createFile=async()=> (await import('./report-export.js')).createReportFile(snapshot,'pdf');
   if(button.hasAttribute('data-report-print')){
    openDocumentPrint({current,createFile:async()=>{
     const file=await createFile();if(current())recordFinance?.(file);return file;
    }});
    return;
   }
   if(button.hasAttribute('data-report-share')){
    openPreparedPdfShare({title:i18nT('Raporu paylaş'),description:i18nT('Seçili dönemin PDF raporu hazırlanıyor.'),current,createFile,onFileReady:recordFinance});
    return;
   }
   busy=true;root.exportBusy=true;const status=root.querySelector('.report-export-status');
   const controls=[...root.querySelectorAll('button,[type=checkbox]'),...(actionHost?.querySelectorAll('button')||[])].map(element=>({element,disabled:element.disabled}));
   controls.forEach(({element})=>element.disabled=true);status.textContent=i18nT('Rapor dosyası hazırlanıyor…');
   try{const {downloadReport}=await import('./report-export.js');const result=await downloadReport(snapshot,button.dataset.export,current,recordFinance);if(current())status.textContent=downloadStatus(result);}
   catch(e){if(valid())status.textContent=i18nT('Rapor hazırlanamadı: ')+e.message;}
   finally{busy=false;root.exportBusy=false;if(valid())controls.forEach(({element,disabled})=>{if(element.isConnected)element.disabled=disabled;});}
  }
 };
 root.addEventListener('click',handleAction);
 actionHost?.addEventListener('click',handleAction);
 display();void refresh();
}
