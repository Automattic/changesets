"""Isolated HTTP regression checks. Requires fixture from local WP-CLI setup; no secrets printed."""
import argparse, base64, json, urllib.request, urllib.error, urllib.parse
from pathlib import Path
p=argparse.ArgumentParser();p.add_argument('--base',default='http://localhost:8899');p.add_argument('--fixture',required=True);p.add_argument('--private',action='store_true');args=p.parse_args()
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

def login(role):
 import http.cookiejar
 browser=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 browser.open(args.base+'/wp-login.php').read()
 u=f['users'][role]
 form=urllib.parse.urlencode({'log':u['username'],'pwd':u['login_password'],'wp-submit':'Log In','testcookie':'1'}).encode()
 browser.open(urllib.request.Request(args.base+'/wp-login.php',data=form)).read()
 return browser

def finish(label):
 print('Success: '+str(count)+' '+label+' assertions passed.')
