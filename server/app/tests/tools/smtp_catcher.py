# Local SMTP server for tests: accepts any login and writes each message to $MAILDIR/NNNN.eml
import socket, threading, os, itertools
cnt = itertools.count(1)
MAILDIR = os.environ.get('MAILDIR', '/tmp/signallab-mails')
os.makedirs(MAILDIR, exist_ok=True)
def handle(c):
    f = c.makefile('rb'); w = lambda s: c.sendall((s+'\r\n').encode())
    w('220 test ESMTP')
    data=None; auth_step=0
    while True:
        line = f.readline()
        if not line: break
        s = line.decode(errors='replace').rstrip('\r\n')
        if data is not None:
            if s == '.':
                open(os.path.join(MAILDIR, f'{next(cnt):04d}.eml'),'w').write('\n'.join(data)); data=None; w('250 OK queued')
            else: data.append(s[1:] if s.startswith('..') else s)
            continue
        if auth_step==1: auth_step=2; w('334 UGFzc3dvcmQ6'); continue
        if auth_step==2: auth_step=0; w('235 Authentication successful'); continue
        u = s.upper()
        if u.startswith('EHLO'): c.sendall(b'250-test\r\n250-AUTH LOGIN\r\n250 OK\r\n')
        elif u.startswith('AUTH LOGIN'): auth_step=1; w('334 VXNlcm5hbWU6')
        elif u.startswith('MAIL') or u.startswith('RCPT'): w('250 OK')
        elif u=='DATA': data=[]; w('354 go')
        elif u=='QUIT': w('221 bye'); break
        else: w('250 OK')
    c.close()
s=socket.socket(); s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR,1); s.bind(('127.0.0.1',2525)); s.listen(5)
while True:
    c,_=s.accept(); threading.Thread(target=handle,args=(c,),daemon=True).start()
