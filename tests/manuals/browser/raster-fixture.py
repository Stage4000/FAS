"""Original deterministic image-heavy PDFs. No network, server or real manual data."""
from pathlib import Path
import random,hashlib,json
root=None

def make(root,n=48,dense=False):
    # Uncompressed RGB image pages: each image is 1,047,552 bytes.
    objects={}; image_ids=[]; content_ids=[]; page_ids=[]
    def add(data):
        key=len(objects)+1; objects[key]=data; return key
    catalog=add(b'');pages=add(b'')
    for i in range(n):
        raw=random.Random(20261112+i).randbytes(1024*341*3)
        image_ids.append(add(b'<< /Type /XObject /Subtype /Image /Width 1024 /Height 341 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Length '+str(len(raw)).encode()+b' >>\nstream\n'+raw+b'\nendstream'))
    # A readable vector-only cover followed by independent image pages, or dense
    # first page needing every image. Both have the same independent image assets.
    if dense:
        page_assets=[list(range(n))]
    else:
        page_assets=[[]]+[[i] for i in range(n)]
    for assets in page_assets:
        if not assets: stream=b'0.9 0 0.2 rg 25 25 400 550 re f 1 1 1 rg 50 50 350 500 re f'
        else:
            stream=b'\n'.join(f'q 400 0 0 {max(1,550//len(assets))} 25 {25+(idx*(550//len(assets)))} cm /Im{i} Do Q'.encode() for idx,i in enumerate(assets))
        content=add(b'<< /Length '+str(len(stream)).encode()+b' >>\nstream\n'+stream+b'\nendstream')
        resources=b' '.join(f'/Im{i} {image_ids[i]} 0 R'.encode() for i in assets)
        page_ids.append(add(b'<< /Type /Page /Parent '+str(pages).encode()+b' 0 R /MediaBox [0 0 450 600] /Resources << /XObject << '+resources+b' >> >> /Contents '+str(content).encode()+b' 0 R >>'))
    objects[catalog]=f'<< /Type /Catalog /Pages {pages} 0 R >>'.encode()
    objects[pages]=b'<< /Type /Pages /Count '+str(len(page_ids)).encode()+b' /Kids ['+b' '.join(f'{p} 0 R'.encode() for p in page_ids)+b'] >>'
    data=bytearray(b'%PDF-1.7\n%\xe2\xe3\xcf\xd3\n');offsets=[0]
    for key,value in objects.items():
        offsets.append(len(data));data.extend(f'{key} 0 obj\n'.encode()+value+b'\nendobj\n')
    xref=len(data);data.extend(f'xref\n0 {len(offsets)}\n0000000000 65535 f \n'.encode())
    for off in offsets[1:]:data.extend(f'{off:010} 00000 n \n'.encode())
    data.extend(f'trailer\n<< /Size {len(offsets)} /Root {catalog} 0 R >>\nstartxref\n{xref}\n%%EOF\n'.encode())
    name=f'{n}mib-'+('dense-first' if dense else 'separate-pages')+'.pdf';(root/name).write_bytes(data)
    return dict(file=name,bytes=len(data),pages=len(page_ids),images=n,sha256=hashlib.sha256(data).hexdigest(),layout='all images needed for first page' if dense else 'vector cover then independent image pages',compression='uncompressed RGB; not representative compression ratios')
