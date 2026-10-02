"""True concurrent localhost HTTP checks; requires the disposable publication barrier plugin."""
import sys, threading, time
from pathlib import Path
exec(Path(__file__).with_name('http-regressions.py').read_text().split("s,b=ability('create',None")[0])
barrier=Path(args.fixture).parent/'publish-barrier';barrier.unlink(missing_ok=True)
s,b=ability('create','contributor',{'title':'Concurrent HTTP proposal'});check(s==200,'Concurrent fixture created');cs=b['changeset_id']
posts=[]
for label in ('FIRST','SECOND'):
 s,b=ability('save','contributor',{'changeset_id':cs,'type':'content','post_type':'post','title':'Concurrent '+label,'content':'CS_CONCURRENT_REVIEWED_'+label});check(s==200,'Concurrent '+label+' draft created');posts.append(b['staged_id'])
s,b=ability('approve','editor',{'changeset_id':cs});check(s==200,'Concurrent proposal approved')
result={}
def publish():
 s,body,_=request('/?rest_route=/wp-abilities/v1/abilities/changesets/publish/run&cs_pause_publish=1','editor',{'input':{'changeset_id':cs}})
 result.update(status=s,body=json.loads(body))
t=threading.Thread(target=publish);t.start()
deadline=time.monotonic()+10
while not barrier.exists() and time.monotonic()<deadline:time.sleep(.02)
check(barrier.exists(),'Publication paused after approved snapshot')
s,b=ability('save','contributor',{'changeset_id':cs,'type':'content','title':'Concurrent unapproved item','content':'CS_CONCURRENT_UNAPPROVED'});check(s in (401,403),'Concurrent ability mutation blocked')
s,body,_=request('/?rest_route=/wp/v2/posts/'+str(posts[1]),'contributor',{'content':'CS_CONCURRENT_UNAPPROVED'});check(s>=400,'Concurrent native REST post mutation blocked')
s,body,_=request('/?rest_route=/wp/v2/posts/'+str(posts[0]),'contributor',{'password':'unreviewed-password','content':'CS_CONCURRENT_UNAPPROVED'});check(s>=400,'Promotion retains lock while first item is still a draft')
s,body,_=request('/?rest_route=/wp/v2/posts/'+str(posts[1])+'&force=true','contributor',method='DELETE');check(s>=400,'Concurrent native REST deletion blocked')
s,b=ability('publish','editor',{'changeset_id':cs});check(b.get('code')=='cs_publishing','Duplicate concurrent publication blocked')
s,b=ability('approve','editor',{'changeset_id':cs});check(b.get('code')=='cs_publishing','Concurrent reapproval blocked')
t.join(timeout=15);check(not t.is_alive() and result.get('status')==200,'Original publication completes')
s,body,_=request('/?p='+str(posts[1]));check('CS_CONCURRENT_REVIEWED_SECOND' in body and 'CS_CONCURRENT_UNAPPROVED' not in body,'Only approved snapshot reaches public content')
barrier.unlink(missing_ok=True)
print('Success: '+str(count)+' concurrent HTTP assertions passed.')
