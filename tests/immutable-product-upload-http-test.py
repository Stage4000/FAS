from pathlib import Path
import tempfile,subprocess,socket,time,urllib.request,json,hashlib,struct,zlib,os,shutil
root=Path(__file__).resolve().parents[1]
php=Path(shutil.which(os.environ.get('PHP_BINARY','php')) or os.environ.get('PHP_BINARY','php')).resolve()
if not php.is_file():raise RuntimeError('PHP executable unavailable')
helper=root/'src/utils/ImmutableProductUpload.php'
def png():
 def chunk(t,d):return struct.pack('>I',len(d))+t+d+struct.pack('>I',zlib.crc32(t+d))
 return b'\x89PNG\r\n\x1a\n'+chunk(b'IHDR',struct.pack('>IIBBBBB',2,2,8,2,0,0,0))+chunk(b'IDAT',zlib.compress(b'\0'+b'\xff\0\0'*2+b'\0'+b'\0\xff\0'*2))+chunk(b'IEND',b'')
with tempfile.TemporaryDirectory(prefix='fas-upload-http-') as d:
 d=Path(d);(d/'site').mkdir()
 # Controlled loopback-only synthetic upload harness; not an application deployment.
 (d/'router.php').write_text('<?php require '+json.dumps(str(helper))+'; header("Content-Type: application/json"); try { echo json_encode(FAS\\Utils\\ImmutableProductUpload::receive($_FILES["photo"]["tmp_name"], '+json.dumps(str(d/'site'))+')); } catch(Throwable $e) { http_response_code(400); echo json_encode(["error"=>"upload refused"]); }')
 sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close()
 server=subprocess.Popen([str(php),'-S',f'127.0.0.1:{port}',str(d/'router.php')],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 try:
  for _ in range(50):
   if server.poll() is not None:raise RuntimeError('PHP fixture server failed')
   try:
    s=socket.create_connection(('127.0.0.1',port),timeout=.1);s.close();break
   except OSError:time.sleep(.05)
  content=png();boundary='fixture-boundary';body=(f'--{boundary}\r\nContent-Disposition: form-data; name="photo"; filename="wrong.php"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode()+content+f'\r\n--{boundary}--\r\n'.encode())
  req=lambda:urllib.request.Request(f'http://127.0.0.1:{port}',data=body,headers={'Content-Type':f'multipart/form-data; boundary={boundary}'})
  a=json.load(urllib.request.urlopen(req(),timeout=5));b=json.load(urllib.request.urlopen(req(),timeout=5))
  assert a['path']!=b['path'] and a['sha256']==b['sha256']
  assert a['path'].endswith('.png') and a['sha256']==hashlib.sha256(content).hexdigest()
  assert (d/'site'/a['path'].lstrip('/')).read_bytes()==content
  assert len(list((d/'site/gallery/uploads/immutable').iterdir()))==2
  (d/'site'/a['path'].lstrip('/')).unlink()
  assert (d/'site'/b['path'].lstrip('/')).read_bytes()==content
  print('PASS real loopback HTTP multipart upload gate, actual PNG canonical extension, separate upload identities, deletion isolation, original bytes, no temporary leftovers')
 finally:
  server.terminate();server.wait(timeout=5)
