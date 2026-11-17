"""Build an import candidate from the node types present in the supplied BAR export.

No token is copied from the source attachment. BAR import/device validation remains required.
"""
import json
from pathlib import Path

nodes = {}
def node(key, kind, title, ok=None, fail=None, x=0, y=0, **extra):
    nodes[key] = dict(itemBriefText=title,itemType=kind,itemActionType=kind,itemAtOk=ok,itemAtFail=fail,x=x,y=y,**extra)

def condition(key, variable, value, ok, fail, x=0,y=0):
    right = str(value) if isinstance(value,int) else json.dumps(value)
    node(key,'Condition',title='Czy '+variable+' = '+str(value)+'?',ok=ok,fail=fail,x=x,y=y,
         trueif=f'<VARIABLE {variable}> == {right}',editorData={'condition':{'conditions':[{'type':'condition','left':f'<VARIABLE {variable}>','right':right,'operation':'=='}],'bindOperation':'and'}})

def message(key,title,text,ok,x=0,y=0):
    node(key,'Message',title,ok=ok,x=x,y=y,messageIcon='information',dismissAfter=0,messageString='<p>'+text+'</p>')

def question(key,title,text,ok,fail,x=0,y=0):
    node(key,'Question',title,ok,fail,x,y,questionString='<p>'+text+'</p>',defaultButton='No')

def server(key,title,path,method,reply,ok,fail,payload=None,x=0,y=0):
    node(key,'Server Message',title,ok,fail,x,y,requestTimeout=20,returnStoreVariable='<VARIABLE HttpReturnCode>',
         toServerPayload={'url':'http://REPLACE_WITH_API_HOST:8089/v1/'+path,'requestMethod':method,'requestType':'application/json',
                          'payload':payload or {},'extraheaders':'Authorization: Bearer REPLACE_WITH_API_TOKEN',
                          'reply':{name:'<VARIABLE '+variable+'>' for name,variable in reply.items()}})

node('boot','Booted','Start',ok='serial',x=-1600,y=0)
node('serial','Set value','Serial sprzętowy',ok='reset0',x=-1300,y=0,setValueExp='<VARIABLE SerialNumber> = <REPORTPATH blancco_data.blancco_hardware_report.system.serial>')
variables=['RequestKey','JobId','AttemptId','ImageName','HasDpk','Decision','ApiMessage','ConfigurationVersion','PreviousInstalledAt','OrderNumber','AttemptState']
for i,name in enumerate(variables):
    node('reset'+str(i),'Set value','Wyzeruj '+name,ok='reset'+str(i+1) if i+1<len(variables) else 'key',x=-1000+i*210,y=0,setValueExp=f'<VARIABLE {name}> = ""')
server('key','Pobierz identyfikator uruchomienia','request-key','POST',{'requestKey':'RequestKey'},'key_ok','key_error',x=1500,y=0)
condition('key_ok','HttpReturnCode',200,'lookup','key_error',1800,0)
message('key_error','Brak komunikacji z API','Nie uzyskano identyfikatora uruchomienia. Nie rozpoczęto instalacji.','key_retry',1800,400)
question('key_retry','Ponów komunikację','Spróbować ponownie?','key','finish_fail',2100,400)
server('lookup','Pobierz zlecenie','lookup?serial=<VARIABLE SerialNumber>','GET',
       {'decision':'Decision','jobId':'JobId','configurationVersion':'ConfigurationVersion','imageName':'ImageName','hasDpk':'HasDpk','message':'ApiMessage','installedAt':'PreviousInstalledAt','orderNumber':'OrderNumber'},
       'lookup_ok','lookup_error',x=2100,y=0)
condition('lookup_ok','HttpReturnCode',200,'auto','lookup_error',2400,0)
message('lookup_error','Nie pobrano danych','API jest niedostępne lub odrzuciło żądanie. Nie rozpoczęto instalacji.','lookup_retry',2400,700)
question('lookup_retry','Ponów odczyt','Spróbować ponownie pobrać dane?','lookup','finish_fail',2700,700)
condition('auto','Decision','AUTO','start_auto','retry',2700,0)
condition('retry','Decision','ASK_RETRY','retry_info','manual',2700,300)
message('retry_info','Ponowna instalacja','Ten komputer był już instalowany: &lt;VARIABLE PreviousInstalledAt&gt;. Zamówienie: &lt;VARIABLE OrderNumber&gt;. Obraz: &lt;VARIABLE ImageName&gt;.','retry_question',3100,300)
question('retry_question','Wybór ponownej instalacji','Zainstalować ponownie według zamówienia? Nie: wybór ręczny.','start_retry','manual_key',3500,300)
condition('manual','Decision','MANUAL','manual_info','wait',2700,1000)
condition('wait','Decision','WAIT','manual_info','blocked',2700,1300)
condition('blocked','Decision','BLOCKED','manual_info','busy_info',2700,1600)
message('manual_info','Dane do instalacji','&lt;VARIABLE ApiMessage&gt;','manual_confirm',3100,1000)
question('manual_confirm','Wybór ręczny','Przejść do ręcznego wyboru obrazu i klucza?','manual_key','finish_fail',3500,1000)
message('busy_info','Nie można rozpocząć','&lt;VARIABLE ApiMessage&gt;. Sprawdź stan urządzenia w aplikacji. Nie rozpoczynaj drugiej instalacji.','finish_fail',3100,1600)
question('manual_key','Klucz dla instalacji ręcznej','Czy użyć klucza produktu?','start_manual_key','start_manual_no_key',3900,1000)
for key,retry_value,y in [('start_auto',False,-400),('start_retry',True,0)]:
    server(key,'Rozpocznij według zamówienia','attempts/start','POST',{'attemptId':'AttemptId','state':'AttemptState','imageName':'ImageName','hasDpk':'HasDpk'},
           key+'_200','start_error',payload={'serial':'<VARIABLE SerialNumber>','jobId':'<VARIABLE JobId>','configurationVersion':'<VARIABLE ConfigurationVersion>','mode':'order','requestKey':'<VARIABLE RequestKey>','retryConfirmed':retry_value},x=4000,y=y)
    condition(key+'_200','HttpReturnCode',200,'state_order',key+'_201',4300,y)
    condition(key+'_201','HttpReturnCode',201,'state_order','start_error',4600,y)
for key,target,y in [('start_manual_key','manual_state_key',700),('start_manual_no_key','manual_state_no_key',1200)]:
    server(key,'Zarejestruj ręczną próbę','attempts/start','POST',{'attemptId':'AttemptId','state':'AttemptState'},key+'_200','start_error',
           payload={'serial':'<VARIABLE SerialNumber>','mode':'manual','requestKey':'<VARIABLE RequestKey>'},x=4300,y=y)
    condition(key+'_200','HttpReturnCode',200,target,key+'_201',4600,y)
    condition(key+'_201','HttpReturnCode',201,target,'start_error',4900,y)
condition('state_order','AttemptState','started','order_key','already_finished',5000,-200)
condition('order_key','HasDpk','YES','reimage_key','reimage_no_key',5300,-200)
condition('manual_state_key','AttemptState','started','reimage_manual_key','already_finished',5200,700)
condition('manual_state_no_key','AttemptState','started','reimage_manual_no_key','already_finished',5200,1200)
for key,with_key,manual,y in [('reimage_key',True,False,-400),('reimage_no_key',False,False,0),('reimage_manual_key',True,True,600),('reimage_manual_no_key',False,True,1000)]:
    extra={'imageName':'prompt' if manual else 'variable','installDigitalProductKey':with_key,'useCitizenshipLicense':False}
    if not manual:extra['getImageNameFromVariable']='<VARIABLE ImageName>'
    node(key,'Reimage',('Ręcznie' if manual else 'Według zamówienia')+(' z kluczem' if with_key else ' bez klucza'),ok='complete',fail='failed',x=5700,y=y,**extra)
message('already_finished','Próba ma już wynik','Ta próba ma już zapisany wynik. Nie uruchamiaj ponownie Reimage w tym uruchomieniu workflow.','finish_fail',5400,1600)
message('start_error','Nie rozpoczęto próby','API nie potwierdziło startu. Zlecenie mogło się zmienić albo urządzenie ma inną aktywną próbę.','start_retry_info',4400,1900)
message('start_retry_info','Sprawdź stan','Sprawdź zlecenie w aplikacji. Ponów wywołanie startu w edytorze tylko z tym samym RequestKey; przy zmianie danych uruchom nowy workflow.','finish_fail',4800,1900)
server('complete','Zapisz sukces Reimage','attempts/<VARIABLE AttemptId>/complete','POST',{},'complete_ok','complete_error',x=6100,y=0)
condition('complete_ok','HttpReturnCode',200,'report','complete_error',6400,0)
message('complete_error','Reimage wykonany, brak potwierdzenia','Reimage zakończony. Nie zapisano potwierdzenia. Nie instaluj ponownie systemu.','complete_retry',6400,600)
question('complete_retry','Ponów zapis','Ponowić wyłącznie zapis wyniku?','complete','report',6800,600)
server('failed','Zapisz niepowodzenie Reimage','attempts/<VARIABLE AttemptId>/fail','POST',{},'failed_ok','failed_error',payload={'failureCode':'REIMAGE_FAILED'},x=6100,y=1300)
condition('failed_ok','HttpReturnCode',200,'finish_fail','failed_error',6400,1300)
message('failed_error','Brak zapisu błędu','Reimage nie powiódł się i nie zapisano jego wyniku. Sprawdź próbę w aplikacji.','finish_fail',6800,1300)
node('report','Send Report','Wyślij raport Blancco',ok='finish',fail='report_error',x=7200,y=0)
message('report_error','Brak raportu','Nie udało się wysłać raportu do Blancco. Wynik Reimage jest odrębnym zdarzeniem.','finish',7500,600)
node('finish','Finalize','Zakończ',x=7900,y=0,showFail=False,systemRestart=True,systemShutdown=False)
node('finish_fail','Finalize','Zatrzymaj do sprawdzenia',x=7900,y=1500,showFail=True,systemRestart=False,systemShutdown=False)
# API assists installation; it must not prevent manual image selection.
for key in ['key', 'key_ok', 'lookup', 'lookup_ok']:
    nodes[key]['itemAtFail'] = 'manual_reset'
for key in ['manual', 'wait', 'blocked']:
    nodes[key]['itemAtOk'] = 'manual_reset'
nodes['blocked']['itemAtFail'] = 'manual_reset'
nodes['retry_question']['itemAtFail'] = 'manual_reset'
for key in ['start_auto', 'start_auto_201', 'start_retry', 'start_retry_201', 'state_order']:
    nodes[key]['itemAtFail'] = 'manual_reset'
node('manual_reset', 'Set value', 'Wyczyść próbę przed wyborem ręcznym', ok='manual_key',
     setValueExp='<VARIABLE AttemptId> = ""', x=3300, y=1000)
nodes['manual_key']['itemAtOk'] = 'manual_online_key'
nodes['manual_key']['itemAtFail'] = 'manual_online_no_key'
for suffix, target in [('key', 'reimage_manual_key'), ('no_key', 'reimage_manual_no_key')]:
    condition('manual_online_'+suffix, 'RequestKey', '', target, 'start_manual_'+suffix, 4000, 1000)
    node('manual_clear_'+suffix, 'Set value', 'Kontynuuj ręcznie bez zapisu próby', ok=target,
         setValueExp='<VARIABLE AttemptId> = ""', x=5300, y=1400)
    for key in ['start_manual_'+suffix, 'start_manual_'+suffix+'_201', 'manual_state_'+suffix]:
        nodes[key]['itemAtFail'] = 'manual_clear_'+suffix
for key in ['reimage_key', 'reimage_no_key', 'reimage_manual_key', 'reimage_manual_no_key']:
    nodes[key]['itemAtOk'] = 'has_attempt_success'
    nodes[key]['itemAtFail'] = 'has_attempt_failure'
condition('has_attempt_success', 'AttemptId', '', 'report', 'complete', 6000, 0)
condition('has_attempt_failure', 'AttemptId', '', 'report_fail', 'failed', 6000, 1300)
nodes['complete']['itemAtFail'] = 'report'
nodes['complete_ok']['itemAtFail'] = 'report'
nodes['failed']['itemAtOk'] = 'report_fail'
nodes['failed']['itemAtFail'] = 'report_fail'
node('report_fail', 'Send Report', 'Wyślij raport po błędzie Reimage', ok='finish_fail', fail='finish_fail', x=7200, y=1500)
nodes['report']['itemAtFail'] = 'finish'
for key in ['key_error', 'key_retry', 'lookup_error', 'lookup_retry', 'manual_info', 'manual_confirm',
            'busy_info', 'already_finished', 'start_error', 'start_retry_info', 'complete_error',
            'complete_retry', 'failed_ok', 'failed_error', 'report_error']:
    del nodes[key]
ids={key:i for i,key in enumerate(nodes)}
objects=[]
for key,entry in nodes.items():
    entry['item_id']=ids[key]
    entry['itemAtOk']=ids[entry['itemAtOk']] if entry['itemAtOk'] else -1
    entry['itemAtFail']=ids[entry['itemAtFail']] if entry['itemAtFail'] else -1
    objects.append(entry)
workflow={'startItem':'Booted','reportToXml':True,'autoStart':True,'useCases':[],'processors':[],'coreActivities':[],
          'description':'Wersja do importu testowego. Uzupełnij API_HOST i API_TOKEN. Wymagany test na urządzeniu przed produkcją.',
          'editorMetadata':{'editorGeneration':'V2','editorVersion':'7.20.0','width':25000,'height':20000,'minimizeItems':False,'profile':'bde','type':'workflow','x':0,'y':0},
          'name':'AMSO Install v1 - test import','objects':objects,'version':'7.20.0'}
# Preserve the V2 condition editor and Qt Rich Text representation.
for entry in objects:
    editor = entry.setdefault('editorData', {})
    editor.update(x=entry['x'], y=entry['y'])
    if entry['itemType'] == 'Condition':
        condition_data = editor['condition']
        condition_data.pop('bindOperation', None)
        condition_data.update(outerBindOperation='and', stringRepresentation=entry['trueif'], textCondition='')
        entry['connections'] = [{'id': entry[edge], 'name': name} for edge, name in [('itemAtOk', 'Success'), ('itemAtFail', 'Failure')] if entry[edge] >= 0]
    for field in ['messageString', 'questionString']:
        if field in entry:
            entry[field] = ('<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.0//EN" "http://www.w3.org/TR/REC-html40/strict.dtd">'
                '<html><head><meta name="qrichtext" content="1" /><meta charset="utf-8" />'
                '<style type="text/css">p, li { white-space: pre-wrap; }</style></head>'
                '<body style="font-family:Lato; font-size:22px; font-weight:400; font-style:normal;">'
                + entry[field] + '</body></html>')
Path(__file__).with_name('install-v1.json').write_text(json.dumps(workflow,ensure_ascii=False,indent=2)+'\n')
