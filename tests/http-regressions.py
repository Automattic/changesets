"""Isolated HTTP regression checks. Requires fixture from local WP-CLI setup; no secrets printed."""
import argparse, base64, json, urllib.request, urllib.error, urllib.parse
from pathlib import Path
p=argparse.ArgumentParser();p.add_argument('--base',default='http://localhost:8899');p.add_argument('--fixture',required=True);args=p.parse_args()
if urllib.parse.urlparse(args.base).hostname not in ('localhost','127.0.0.1','::1'):
 raise SystemExit('Security tests require an isolated localhost installation.')
f=json.loads(Path(args.fixture).read_text().split('\n',1)[1]);sessions={};count=0

def check(ok,label):
 global count
 if not ok: raise AssertionError(label)
 count+=1; print('PASS '+label)
def request(path,role=None,data=None,method=None,extra=None):
 headers={'Content-Type':'application/json'}
 if role:
  u=f['users'][role];headers['Authorization']='Basic '+base64.b64encode((u['username']+':'+u['password']).encode()).decode()
 if extra: headers.update(extra)
 req=urllib.request.Request(args.base+path,data=None if data is None else json.dumps(data).encode(),headers=headers,method=method)
 try: response=urllib.request.urlopen(req,timeout=30)
 except urllib.error.HTTPError as error: response=error
 with response:return response.code,response.read().decode(),dict(response.headers)
def ability(name,role,data,method='POST'):
 path='/?rest_route=/wp-abilities/v1/abilities/changesets/'+name+'/run'
 if method in ('GET','DELETE'):
  path+='&'+urllib.parse.urlencode({'input['+k+']':v for k,v in data.items()})
  status,body,_=request(path,role,method=method)
 else:
  status,body,_=request(path,role,{'input':data},method)
 try: result=json.loads(body)
 except ValueError: result={'invalid_response':True}
 return status,result
def mcp(name,role,data):
 if role not in sessions:
  status,body,headers=request('/?rest_route=/mcp/mcp-adapter-default-server',role,{'jsonrpc':'2.0','id':1,'method':'initialize','params':{'protocolVersion':'2025-11-25','capabilities':{},'clientInfo':{'name':'isolated-security-tests','version':'1'}}})
  check(status==200 and 'result' in json.loads(body),'MCP authenticates '+role)
  sessions[role]={k:v for k,v in headers.items() if k.lower()=='mcp-session-id'}
  sessions[role]['MCP-Protocol-Version']='2025-11-25'
 status,body,_=request('/?rest_route=/mcp/mcp-adapter-default-server',role,{'jsonrpc':'2.0','id':2,'method':'tools/call','params':{'name':'mcp-adapter-execute-ability','arguments':{'ability_name':'changesets/'+name,'parameters':data}}},extra=sessions[role])
 obj=json.loads(body)
 if 'error' in obj:return False,obj
 result=obj.get('result',{})
 for item in result.get('content',[]):
  if item.get('type')=='text':
   try:return not result.get('isError',False) and json.loads(item['text']).get('success',False),json.loads(item['text'])
   except ValueError:pass
 return False,obj

s,b=ability('create',None,{'title':'Unauthenticated'});check(s in (401,403),'Anonymous cannot create changeset')
s,b=ability('list','contributor',{'per_page':100},'GET');check(s==200 and f['uuid'] not in json.dumps(b),'Contributor list does not disclose Admin UUID')
for action,method in [('get','GET'),('discard','DELETE'),('save','POST')]:
 data={'changeset_id':f['admin_changeset']}
 if action=='save':data.update(type='content',title='Unowned mutation')
 s,b=ability(action,'contributor',data,method);check(s in (401,403),'Contributor cannot '+action+' another user changeset')
s,body,_=request('/?rest_route=/wp/v2/changeset&status=draft&author='+str(f['users']['administrator']['id']),'contributor');check('Admin private draft' not in body,'Native REST author filter cannot bypass ownership')
s,b=ability('create','author',{'title':'Native publish boundary'});check(s==200,'Author can create own proposal');author_cs=b['changeset_id']
s,b=ability('save','author',{'changeset_id':author_cs,'type':'content','post_type':'post','title':'Native unpublished proposal','content':'CS_AUTHOR_UNAPPROVED_MARKER'});check(s==200,'Author can stage own post');author_draft=b['staged_id']
s,body,_=request('/?rest_route=/wp/v2/posts/'+str(author_draft),'author',{'status':'publish'});check(s==200 and json.loads(body).get('status')=='draft','Native Author publish stays staged before database publication')
s,body,_=request('/?p='+str(author_draft));check('CS_AUTHOR_UNAPPROVED_MARKER' not in body,'Native publish attempt does not expose unapproved content')
s,b=ability('create','contributor',{'title':'HTTP Contributor proposal'});check(s==200 and 'changeset_id' in b,'Contributor creates own proposal');cs=b['changeset_id'];uuid=b['uuid']
s,b=ability('save','contributor',{'changeset_id':cs,'type':'content','post_type':'page','title':'HTTP Proposal','content':'CS_HTTP_CONTRIBUTOR_MARKER','slug':'cs-http-proposal-'+str(cs)});check(s==200 and 'staged_id' in b,'Contributor stages own content');draft=b['staged_id']
s,b=ability('save','contributor',{'changeset_id':cs,'type':'content','post_type':'post','title':'Inert markup test','content':'<script id="CS_CONTENT_SCRIPT_MARKER">/* inert */</script>CS_SAFE_CONTENT_MARKER'});check(s==200,'Contributor content staging remains available with sanitization')
s,body,_=request('/?p='+str(b['staged_id'])+'&changeset='+uuid);check('<script id="CS_CONTENT_SCRIPT_MARKER">' not in body,'WordPress strips Contributor content scripts')
for role in ('contributor','author'):
 for action in ('approve','publish'):
  s,b=ability(action,role,{'changeset_id':cs});check(s in (401,403),'REST '+role+' cannot '+action)
for key,value in [('users_can_register','1'),('default_role','administrator')]:
 s,b=ability('save','contributor',{'changeset_id':cs,'type':'setting','key':key,'value':value});check(s in (401,403),'Contributor cannot stage '+key)
for key in (' default_role ','DEFAULT_ROLE','users_can_register','wp_user_roles'):
 s,b=ability('save','administrator',{'changeset_id':f['admin_changeset'],'type':'setting','key':key,'value':'administrator'});check('cs_denylisted_option'==b.get('code'),'Admin denied unsafe/alias option '+key)
s,b=ability('save','contributor',{'changeset_id':cs,'type':'styles','styles':{'css':'</style><script id="CS_STYLE_HTTP_MARKER">/* inert */</script>'}});check(b.get('code')=='cs_unsafe_css','Contributor cannot stage script markup through styles')
for label,query,cookie,visible in [('numeric','changeset='+str(cs),None,False),('numeric-like','changeset=1e2',None,False),('array','changeset[]='+uuid,None,False),('unknown','changeset=aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',None,False),('UUID','changeset='+uuid,None,True),('UUID cookie','',uuid,True),('numeric cookie','',str(cs),False)]:
 path='/?page_id='+str(draft)+(('&'+query) if query else '')
 s,body,_=request(path,extra={'Cookie':'changeset='+cookie} if cookie else None)
 check(('CS_HTTP_CONTRIBUTOR_MARKER' in body)==visible,label+' HTTP preview confidentiality')
s,body,_=request('/?changeset='+f['style_uuid']);check('<script id="CS_STYLE_INJECTION_MARKER">' not in body,'Legacy global styles cannot emit script tag')
s,body,_=request('/?rest_route=/wp/v2/pages/'+str(draft));check(s in (401,403,404) and 'CS_HTTP_CONTRIBUTOR_MARKER' not in body,'Native REST cannot anonymously disclose staged draft')
for token in (f['uuid'],str(f['admin_changeset'])):
 s,body,_=request('/wp-login.php?action=register&changeset='+token);check('id="registerform"' not in body,'Query preview does not enable registration')
 s,body,_=request('/wp-login.php?action=register',extra={'Cookie':'changeset='+token});check('id="registerform"' not in body,'Cookie preview does not enable registration')
for role in ('contributor','author'):
 for action in ('approve','publish'):
  ok,b=mcp(action,role,{'changeset_id':cs});check(not ok,'MCP '+role+' cannot '+action)
ok,b=mcp('create','contributor',{'title':'MCP legitimate proposal'});check(ok,'MCP Contributor can create proposal')
ok,b=mcp('save','contributor',{'changeset_id':cs,'type':'setting','key':'default_role','value':'administrator'});check(not ok,'MCP Contributor cannot stage registration role')
s,b=ability('get','contributor',{'changeset_id':cs},'GET');check(s==200,'Contributor can inspect own proposal')
s,b=ability('save','contributor',{'changeset_id':cs,'type':'styles','styles':{'color':{'text':'#123456'}}});check(s==200,'Contributor can propose safe visual styles')
s,b=ability('approve','editor',{'changeset_id':cs});check(s==200,'Editor approves Contributor content')
s,b=ability('save','contributor',{'changeset_id':cs,'type':'content','title':'Added after approval','content':'Unreviewed'});check(s==200,'Contributor can revise own approved proposal')
s,b=ability('publish','editor',{'changeset_id':cs});check(b.get('code')=='cs_not_approved','HTTP mutation requires fresh approval')
s,b=ability('approve','editor',{'changeset_id':cs});check(s==200,'Editor reapproves revised content')
s,b=ability('publish','editor',{'changeset_id':cs});check(s==200 and b.get('status')=='published','Editor publishes approved Contributor workflow')
s,body,_=request('/?page_id='+str(draft));check('CS_HTTP_CONTRIBUTOR_MARKER' in body,'Approved Contributor content is live')
import http.cookiejar
jar=http.cookiejar.CookieJar();browser=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
browser.open(args.base+'/wp-login.php').read()
u=f['users']['contributor']
form=urllib.parse.urlencode({'log':u['username'],'pwd':u['login_password'],'wp-submit':'Log In','testcookie':'1'}).encode()
browser.open(urllib.request.Request(args.base+'/wp-login.php',data=form)).read()
response=browser.open(args.base+'/wp-admin/edit.php?post_type=changeset&all_posts=1');admin_html=response.read().decode()
check(f['uuid'] not in admin_html,'Native Contributor admin list cannot disclose Admin UUID')
try:
 response=browser.open(urllib.request.Request(args.base+'/?rest_route=/wp-abilities/v1/abilities/changesets/create/run',data=json.dumps({'input':{'title':'CSRF'}}).encode(),headers={'Content-Type':'application/json'}));status=response.code
except urllib.error.HTTPError as error:status=error.code
check(status in (401,403),'Cookie-authenticated REST mutation without nonce denied')
s,b=ability('discard','contributor',{'changeset_id':f['admin_changeset']},'DELETE');check(s in (401,403),'Cross-owner discard remains denied after native login')
print('Success: '+str(count)+' HTTP/MCP assertions passed.')
