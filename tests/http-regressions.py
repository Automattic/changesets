"""Public/private localhost HTTP and MCP regressions."""
import json, urllib.request, urllib.error
from http_helpers import args, f, check, request, ability, mcp, login, finish

if args.private:
 path='/?page_id='+str(f['draft'])+'&changeset='+f['uuid']
 s,body,_=request(path);check('CS_HTTP_SECRET_MARKER' not in body,'Private UUID query rejects anonymous viewer')
 s,body,_=request('/?page_id='+str(f['draft']),extra={'Cookie':'changeset='+f['uuid']});check('CS_HTTP_SECRET_MARKER' not in body,'Private UUID cookie rejects anonymous viewer')
 for role in ('administrator','editor','contributor'):
  browser=login(role)
  try: response=browser.open(args.base+path)
  except urllib.error.HTTPError as error: response=error
  with response: body=response.read().decode()
  check(('CS_HTTP_SECRET_MARKER' in body)==(role!='contributor'),'Private HTTP '+role+' access matches capability')
 finish('private HTTP')
 raise SystemExit(0)

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
for role in ('contributor','author'):
 for action in ('approve','publish'):
  s,b=ability(action,role,{'changeset_id':cs});check(s in (401,403),'REST '+role+' cannot '+action)
for key,value in [('users_can_register','1'),('default_role','administrator')]:
 s,b=ability('save','contributor',{'changeset_id':cs,'type':'setting','key':key,'value':value});check(s in (401,403),'Contributor cannot stage '+key)
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
browser=login('contributor')
response=browser.open(args.base+'/wp-admin/edit.php?post_type=changeset&all_posts=1');admin_html=response.read().decode()
check(f['uuid'] not in admin_html,'Native Contributor admin list cannot disclose Admin UUID')
try:
 response=browser.open(urllib.request.Request(args.base+'/?rest_route=/wp-abilities/v1/abilities/changesets/create/run',data=json.dumps({'input':{'title':'CSRF'}}).encode(),headers={'Content-Type':'application/json'}));status=response.code
except urllib.error.HTTPError as error:status=error.code
check(status in (401,403),'Cookie-authenticated REST mutation without nonce denied')
finish('HTTP/MCP')
