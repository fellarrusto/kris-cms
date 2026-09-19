from pathlib import Path
import subprocess, socket, time, urllib.request, tempfile
root=Path(__file__).resolve().parent
runtime=Path(tempfile.mkdtemp(prefix='kris-ui-browser-'))
with socket.socket() as sock:
    sock.bind(('127.0.0.1',0))
    port=sock.getsockname()[1]
log=(runtime/'chrome.log').open('w')
profile=runtime/'profile'
process=subprocess.Popen(['C:/Program Files/Google/Chrome/Application/chrome.exe','--headless=new','--disable-gpu','--no-first-run','--no-default-browser-check','--remote-debugging-address=127.0.0.1','--remote-debugging-port='+str(port),'--user-data-dir='+str(profile),'about:blank'],stdout=log,stderr=log,creationflags=subprocess.CREATE_NO_WINDOW)
try:
    for _ in range(100):
        try:
            urllib.request.urlopen(f'http://127.0.0.1:{port}/json',timeout=1).read()
            break
        except Exception:
            time.sleep(.1)
    result=subprocess.run(['node',str(root/'verifica-browser.cjs'),str(port),str(root)],capture_output=True,text=True,encoding='utf8',timeout=90)
    print(result.stdout)
    print(result.stderr)
    raise SystemExit(result.returncode)
finally:
    process.terminate()
    process.wait(timeout=10)
    log.close()
