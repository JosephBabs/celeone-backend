"""Complete Scramble's inferred spec using the deployed Laravel route inventory.
Usage: python3 scripts/complete-openapi.py raw.json routes.json public/openapi.json
"""
import copy,json,re,sys
from pathlib import Path
raw,routes,output=map(Path,sys.argv[1:4]);spec=json.loads(raw.read_text(encoding='utf-8'));inventory=json.loads(routes.read_text(encoding='utf-8'))
obj={'type':'object','additionalProperties':True}
def body(properties,required=None):
 schema={'type':'object','properties':properties}
 if required:schema['required']=required
 return {'required':bool(required),'content':{'application/json':{'schema':schema}}}
def response(schema,description='Successful response'):
 return {'description':description,'content':{'application/json':{'schema':schema}}}
def envelope(data):return {'type':'object','properties':{'ok':{'type':'boolean'},'data':data,'meta':{'oneOf':[obj,{'type':'array','items':{}}]}},'required':['ok','data']}
def string(**kw):return dict(type='string',**kw)
def normalized(p):return re.sub(r'\{[^}]+\}','{}',p)
paths={};by_route={}
for r in inventory:
 if not r['uri'].startswith('api/') or r['uri'].startswith('api/documentations'):continue
 path='/'+r['uri'][4:];source=next((p for p in spec['paths'] if normalized(p)==normalized(path)),None)
 for method in r['method'].lower().split('|'):
  if method=='head':continue
  op=copy.deepcopy(spec['paths'].get(source,{}).get(method) or spec['paths'].get(source,{}).get('put') if method=='patch' else spec['paths'].get(source,{}).get(method))
  if not op:
   assert path=='/v1/uploads/{id}/content',f'Missing documentation: {method} {path}'
   op={'deprecated':True,'summary':'Legacy signed upload route (unavailable)','description':'This legacy route is registered, but its controller action is absent in the deployed backend. Use uploads/presign and the returned upload URL instead.','responses':{'500':response(obj,'Legacy handler is not implemented')}}
  names=re.findall(r'\{([^}]+)\}',path)
  oldnames=re.findall(r'\{([^}]+)\}',source or path)
  for param in op.get('parameters',[]):
   if param.get('in')=='path' and param['name'] in oldnames:param['name']=names[oldnames.index(param['name'])]
  for name in names:
   if not any(p.get('in')=='path' and p['name']==name for p in op.get('parameters',[])):op.setdefault('parameters',[]).append({'name':name,'in':'path','required':True,'schema':string()})
  op['operationId']=method+'_'+re.sub('[^A-Za-z0-9]+','_',path).strip('_')
  if not op.get('summary'):
   action=r['action'].split('@')[-1].split('\\')[-1]
   op['summary']=re.sub(r'([a-z])([A-Z])',r'\1 \2',action).capitalize()+' — '+path.removeprefix('/v1/').replace('/',' / ')
  middleware=r.get('middleware',[]);auth=any('auth:sanctum' in m or 'Authenticate:sanctum' in m for m in middleware)
  op['security']=[{'BearerAuth':[]}] if auth else []
  notes=[]
  if auth:notes.append('Requires a Sanctum bearer token from login, Google sign-in, or Firebase token exchange.')
  if '/admin/' in path or any('EnsureAdmin' in m for m in middleware):notes.append('Requires an administrator account.')
  for mw in middleware:
   if mw.startswith('throttle:') or 'ThrottleRequests:' in mw:notes.append('Rate limit: '+mw.split(':',1)[1]+' (requests, minutes).')
  if path.startswith('/v1/internal/socket/'):
   op['security']=[{'SocketInternalKey':[]}];notes.append('Server-to-server callback. Never include the internal key in a mobile application.')
  elif path.startswith('/v1/internal/'):
   op['security']=[{'CeleOneInternalKey':[]}];notes.append('Server-to-server callback authenticated by the internal service key.')
  if path=='/v1/uploads/{id}/content':
   op['security']=[];notes.append('Requires a valid Laravel signed URL (expires and signature query parameters).')
  desc=op.get('description','')
  if 'Cannot generate request documentation' in desc:
   assert path in ['/v1/auth/password-migration/'+a for a in ['request','verify','complete']], 'Review generator diagnostic: '+path
   desc=''
  op['description']='\n\n'.join(filter(None,[desc,*notes]))
  for status,res in op.get('responses',{}).items():
   if '$ref' not in res and not res.get('description'):res['description']={'200':'Successful response','201':'Created','204':'Deleted','400':'Invalid request','401':'Authentication required','403':'Forbidden','404':'Resource not found','409':'Conflict','410':'Endpoint retired; use the canonical API','422':'Validation failed','429':'Rate limit exceeded','500':'Server error'}.get(status,'HTTP '+status)
  paths.setdefault(path,{})[method]=op;by_route[(path,method)]=r
spec['paths']=paths
spec['info']={'title':'CeleOne API','version':'1.0.0','description':'API reference for the current CeleOne Laravel backend.\n\nBase URL: https://api.celeonetv.com/api. Authenticate using a returned access_token in the Authorize dialog. Dates use ISO 8601 unless a field explicitly describes milliseconds. Scheduled posts are published by the backend scheduler. Profile privacy is enforced for other viewers; owners retain access to their own contact details.\n\nDynamic resource payloads are JSON objects whose fields depend on the collection or domain. GET/HEAD share the same behavior; PUT and PATCH aliases are both listed. Internal callbacks require service keys. The legacy signed content upload route is marked unavailable because its handler is absent. Try it out sends real requests to the live API.'}
spec['servers']=[{'url':'https://api.celeonetv.com/api','description':'Production'}]
spec['components']['securitySchemes']={'BearerAuth':{'type':'http','scheme':'bearer','bearerFormat':'Sanctum','description':'Paste the access_token returned by authentication.'},'SocketInternalKey':{'type':'apiKey','in':'header','name':'X-Socket-Internal-Secret'},'CeleOneInternalKey':{'type':'apiKey','in':'header','name':'X-Celeone-Internal'}}
# Validation inside lock closures and delegated controller methods is not inferred.
for family in ['password-migration','password-reset']:
 for action in ['request','verify','complete']:
  op=paths['/v1/auth/'+family+'/'+action]['post'];props={'email':string(format='email',maxLength=190)}
  if action=='verify':props['code']=string(pattern='^[0-9]{6}$',description='Six-digit code sent to the account email. Expires after 10 minutes; at most five verification attempts.')
  if action=='complete':props.update({'verification_token':string(minLength=40),'password':string(minLength=6,format='password'),'password_confirmation':string(format='password')})
  op['requestBody']=body(props,list(props));data={'sent':{'type':'boolean'},'expires_in':{'type':'integer','const':600}} if action=='request' else ({'verification_token':string(),'expires_in':{'type':'integer','const':900}} if action=='verify' else {'user':{'$ref':'#/components/schemas/User'},'password_migrated':{'type':'boolean'},'access_token':string(),'token_type':string(const='Bearer')})
  op['responses']['200']=response(envelope({'type':'object','properties':data}));op['responses']['422']={'$ref':'#/components/responses/ValidationException'}
  op['description']+='\n\n'+('Only the authenticated account email is accepted.' if family=='password-migration' else 'Email ownership is verified by OTP; no existing session is required. The request response does not reveal whether an account exists.')
paths['/v1/auth/firebase/exchange']['post']['requestBody']=body({'id_token':string(description='Firebase ID token. Legacy aliases idToken, firebase_token, firebaseToken and token are also accepted.'),'device_name':string(default='migrated-mobile')},['id_token'])
for path in ['/v1/push/register-device','/v1/push/permission-state']:
 paths[path]['post']['requestBody']=body({'deviceId':string(maxLength=191),'token':{'type':['string','null'],'maxLength':4096},'platform':{'type':['string','null'],'maxLength':30},'appVersion':{'type':['string','null'],'maxLength':50},'pushEnabled':{'type':['boolean','null']},'permission':obj,'appState':{'type':['string','null'],'maxLength':40},'meta':obj},['deviceId'])
base_update=copy.deepcopy(paths['/v1/agenda/{id}']['put']['requestBody'])
for path in ['/v1/live/sessions/{id}','/v1/notifications/{id}','/v1/packages/{id}']:
 for method in ['put','patch']:paths[path][method]['requestBody']=copy.deepcopy(base_update)
paths['/v1/packages']['post']['requestBody']=copy.deepcopy(paths['/v1/agenda']['post']['requestBody'])
paths['/v1/uploads/{id}/complete']['post']['requestBody']=body({'path':string(description='Fallback storage path when the upload session has no stored path.')})
for path in ['/v1/subscriptions','/v1/subscriptions/{id}']:
 for method,op in paths[path].items():
  if method in ['post','put','patch','delete']:
   op['description']+='\n\nSubscriptions are managed only by verified payment callbacks. This client mutation is always rejected.';op['responses']={'403':response(obj,'Subscriptions are managed by the payment service.')}
# Firestore bridge: associative PHP arrays are JSON objects, not string arrays.
document={'type':'object','properties':{'id':string(),'data':obj},'required':['id','data']}
for method in ['put','patch']:
 op=paths['/v1/resources/{path}'][method];op['requestBody']['content']['application/json']['schema']['properties']['data']=obj;op['responses']['200']=response(document)
 if method=='put':op['responses']['201']=response(document,'Document created')
 op['description']+='\n\nProfile documents in users and user_data can only be changed by their owner or an administrator. The data object supports dotted field paths and transforms: {"__op":"serverTimestamp"}, {"__op":"increment","value":1}, arrayUnion/arrayRemove with values, and deleteField.'
get=paths['/v1/resources/{path}']['get'];get['parameters'] += [{'name':n,'in':'query','schema':schema,'description':description} for n,schema,description in [('__kind',string(enum=['doc']),'Set to doc for a single document; otherwise path identifies a collection.'),('per_page',{'type':'integer','minimum':1,'maximum':500,'default':500},'Collection page size.'),('page',{'type':'integer','minimum':1,'default':1},'Page number.'),('sort',string(),'Dotted payload field used for sorting.'),('direction',string(enum=['asc','desc'],default='desc'),'Sort direction.'),('cursor',string(),'Sort-field cursor value.'),('meta',{'type':'boolean'},'Return collection count and latest update time.'),('filter',{'type':'array','items':string()},'Repeat for JSON filters, e.g. {"field":"__name__","operator":"in","value":["uid"]}.'),('or',{'type':'array','items':string()},'Repeat for JSON arrays of OR filters.')]]
get['responses']['200']=response({'oneOf':[document,{'type':'object','properties':{'items':{'type':'array','items':document},'has_more':{'type':'boolean'},'page':{'type':'integer'}}},{'type':'object','properties':{'count':{'type':'integer'},'latest_updated_at':string()}}]})
get['description']+='\n\nFor users and user_data, hidden email/phone aliases are returned as null to other viewers, including nested legacy profile data. hideEmail and hidePhone are authoritative account settings; contactDetailsRedacted is false for the owner. Hidden fields remain editable by their owner.'
opschema={'type':'array','maxItems':500,'items':{'type':'object','properties':{'path':string(),'method':string(enum=['PUT','PATCH','DELETE']),'data':obj,'merge':{'type':'boolean'}},'required':['path']}}
paths['/v1/resources/batch']['post']['requestBody']=body({'operations':opschema},['operations'])
paths['/v1/resources/transaction']['post']['requestBody']=copy.deepcopy(paths['/v1/resources/batch']['post']['requestBody']);paths['/v1/resources/transaction']['post']['responses']=copy.deepcopy(paths['/v1/resources/batch']['post']['responses'])
# Explicit contracts for aggregated metrics and dynamically decorated content.
integer={'type':'integer','minimum':0}
metric_properties={name:copy.deepcopy(integer) for name in ['publications','totalViews','reach','uniqueAudience','impressions','watchTimeMs','totalLikes','totalComments','shares','legacyViews','engagement','followers','following','profileVisits','scheduledPosts','drafts','pendingReview','rejected']}
metric_properties['reach']['description']='Sum of unique authenticated viewers per content item. A person viewing two items contributes two.'
metric_properties['uniqueAudience']['description']='Distinct authenticated viewers across the entire page, counted once.'
metric_properties['totalViews']['description']='Recorded viewing sessions plus preserved historical counts. Retries are deduplicated; new owner/manager views are excluded.'
metric_properties['legacyViews']['description']='Historical aggregate view baseline retained at migration. Historical reach requires identifiable viewer records.'
content_schema={'type':'object','additionalProperties':True,'properties':{'id':string(),'type':string(),'views_count':integer,'viewCount':integer,'metrics':{'type':'object','additionalProperties':True,'properties':{k:integer for k in ['views','reach','impressions','watchTimeMs']}}}}
analytics={'type':'object','properties':{'pageId':string(),'period':string(const='lifetime'),'asOf':string(format='date-time'),'totals':{'type':'object','properties':metric_properties,'required':list(metric_properties)},'breakdown':{'oneOf':[{'type':'object','additionalProperties':{'type':'object','properties':{k:integer for k in ['publications','views','reach']},'required':['publications','views','reach']}},{'type':'array','items':{},'maxItems':0}]},'topContent':{'type':'array','maxItems':20,'items':{'type':'object','properties':{'id':string(),'type':string(),'title':string(),'views':integer,'reach':integer,'impressions':integer},'required':['id','type','title','views','reach','impressions']}},'definitions':{'type':'object','additionalProperties':string()}},'required':['pageId','period','asOf','totals','breakdown','topContent','definitions']}
paths['/v1/pages/{id}/analytics']['get']['responses']['200']=response(analytics)
paths['/v1/pages/{id}/analytics']['get']['description']+='\n\nAvailable to page owners, administrators, and editors. Covers all published content, including ended live broadcasts, with post/reel copies counted once. Deleted or unpublished content is excluded. No date filter: totals are lifetime values.'
page_content={'type':'object','properties':{'items':{'type':'array','items':content_schema},'total':integer,'hasMore':{'type':'boolean'},'nextOffset':{'type':['integer','null'],'minimum':0}},'required':['items','total','hasMore','nextOffset']}
paths['/v1/pages/{id}/content']['get']['responses']['200']=response(page_content)
paths['/v1/social/content']['get']['responses']['200']=response({'type':'object','properties':{'items':{'type':'array','items':content_schema},'hasMore':{'type':'boolean'},'nextCursor':{'type':['integer','null']}},'required':['items','hasMore','nextCursor']})
paths['/v1/social/content/{type}/{id}']['get']['responses']['200']=response(content_schema)
view_response={'type':'object','properties':{**{k:integer for k in ['views_count','viewCount','reach','impressions','watchTimeMs']},'created':{'type':'boolean'},'excluded':string(enum=['owner'])},'required':['views_count','viewCount','reach','impressions','watchTimeMs','created']}
view=paths['/v1/social/content/{type}/{id}/views']['post'];view['responses']['200']=response(view_response)
view['description']+='\n\nSend a stable eventId for each viewing session and reuse it after failures. Different content cannot reuse an eventId. actorUid optionally binds a queued event to the current account. Count a view only when content is visible for at least two seconds or the viewer opens it. Owner/manager events return excluded=owner without increasing totals.'
for code,description in [('403','Content is not visible or actorUid does not match the session'),('404','Content not found'),('409','Event ID already belongs to different content'),('429','Rate limit exceeded')]:view['responses'][code]=response(obj,description)
for path in ['/v1/social/content/{type}/{id}','/v1/social/content/{type}/{id}/views']:
 for method,op in paths[path].items():
  for param in op.get('parameters',[]):
   if param['name']=='type':param['schema']=string(enum=['posts','reels'] if method=='delete' else ['posts','reels','lives'])
paths['/v1/pages/{id}/visit']['post']['responses']['200']=response({'type':'object','properties':{'created':{'type':'boolean'}},'required':['created']})

# Correct untyped database payloads rather than falsely claiming string values.
def repair(node):
 if isinstance(node,dict):
  for key,value in list(node.items()):
   if key=='properties' and isinstance(value,dict):
    for name,field in list(value.items()):
     if name=='data' and field in [{'type':'string'},{'type':['string','array']}]:value[name]=copy.deepcopy(obj)
     if name in ['data','items'] and isinstance(field,dict) and field.get('type')=='array' and field.get('items')=={'type':'string'}:field['items']=copy.deepcopy(obj)
   repair(value)
 elif isinstance(node,list):
  for value in node:repair(value)
repair(spec)
for path,methods in paths.items():
 for method,op in methods.items():
  assert op.get('responses'),(path,method)
  assert 'Cannot generate' not in json.dumps(op),(path,method)
expected={(('/'+r['uri'][4:]),m.lower()) for r in inventory if r['uri'].startswith('api/') and not r['uri'].startswith('api/documentations') for m in r['method'].split('|') if m!='HEAD'}
actual={(p,m) for p,v in paths.items() for m in v};assert expected==actual,(expected-actual,actual-expected)
extra=json.loads((Path(__file__).resolve().parent.parent/'documentation/companion-openapi.json').read_text(encoding='utf-8'))
assert not (spec['paths'].keys() & extra['paths'].keys())
spec['paths'].update(extra['paths']);spec['components']['securitySchemes'].update(extra['securitySchemes'])
spec['info']['description']+='\n\nAlso includes the notification, call configuration, and payment HTTP endpoints routed by api.celeonetv.com. Companion service contracts were checked against their running source.'
output.parent.mkdir(parents=True,exist_ok=True);output.write_text(json.dumps(spec,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
print(f'Documented {len(actual)} Laravel operations plus {sum(len(v) for v in extra["paths"].values())} companion operations across {len(spec["paths"])} paths.')
