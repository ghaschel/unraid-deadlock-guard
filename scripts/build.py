#!/usr/bin/env python3
"""Build a deterministic, runtime-dependency-free Unraid Slackware package."""
import argparse, hashlib, io, lzma, pathlib, re, tarfile, xml.etree.ElementTree as ET
ROOT=pathlib.Path(__file__).resolve().parents[1]
def build(output):
 version=(ROOT/'VERSION').read_text().strip()
 if not re.fullmatch(r'\d{4}\.\d{2}\.\d{2}(?:[a-z]\d+)?',version):raise ValueError('Invalid release version')
 output.mkdir(parents=True,exist_ok=True);name=f'deadlock-guard-{version}-noarch-1.txz'
 prefix='usr/local/emhttp/plugins/deadlock-guard/'
 files={str(p.relative_to(ROOT/'source')):(p.read_bytes(),0o755 if p.stat().st_mode&0o111 else 0o644) for p in (ROOT/'source').rglob('*') if p.is_file()}
 for name_in in ['LICENSE','icon.svg','README.md']:files[prefix+name_in]=((ROOT/name_in).read_bytes(),0o644)
 files[prefix+'VERSION']=((version+'\n').encode(),0o644)
 files['install/slack-desc']=(b'deadlock-guard: Deadlock Guard (VM and Docker handoff manager)\ndeadlock-guard: Native Unraid 7.3.x beta plugin. MIT license.\n',0o644)
 buffer=io.BytesIO()
 with tarfile.open(fileobj=buffer,mode='w',format=tarfile.USTAR_FORMAT) as tar:
  for path,(data,mode) in sorted(files.items()):
   info=tarfile.TarInfo(path);info.size=len(data);info.mode=mode;info.uid=info.gid=0;info.uname=info.gname='root';info.mtime=0;tar.addfile(info,io.BytesIO(data))
 payload=lzma.compress(buffer.getvalue(),format=lzma.FORMAT_XZ,preset=9,check=lzma.CHECK_CRC64);(output/name).write_bytes(payload)
 sha=hashlib.sha256(payload).hexdigest();md5=hashlib.md5(payload).hexdigest()
 base='https://github.com/ghaschel/unraid-deadlock-manager';url='https://raw.githubusercontent.com/ghaschel/unraid-deadlock-manager/main/deadlock-guard.plg'
 package='/boot/config/plugins/deadlock-guard/packages/'+name
 root=ET.Element('PLUGIN',name='deadlock-guard',author='Guilherme Haschel',version=version,launch='DeadlockGuard',pluginURL=url,support=base+'/issues',icon='fa-shield',min='7.3.0',max='7.3.999')
 ET.SubElement(root,'CHANGES').text=f'### {version} — initial beta\nNative exclusive-group handoffs, VM safety gate, conservative recovery and Settings UI. Host validation required.'
 def script(text,**attrs):ET.SubElement(ET.SubElement(root,'FILE',Run='/bin/bash',**attrs),'INLINE').text='\nset -euo pipefail\n'+text+'\n'
 script('''version=$(sed -n 's/^version="\\(.*\\)"/\\1/p' /etc/unraid-version)
case "$version" in 7.3.[0-9]*) ;; *) echo "Deadlock Guard requires Unraid 7.3.x" >&2; exit 1;; esac
mkdir -p /boot/config/plugins/deadlock-guard/packages''')
 file=ET.SubElement(root,'FILE',Name=package);ET.SubElement(file,'URL').text=base+'/releases/download/'+version+'/'+name;ET.SubElement(file,'SHA256').text=sha;ET.SubElement(file,'MD5').text=md5
 script(f'''printf '%s  %s\\n' '{sha}' '{package}' | sha256sum -c -
if [ -x /usr/local/emhttp/plugins/deadlock-guard/scripts/lifecycle ]; then
  /usr/local/emhttp/plugins/deadlock-guard/scripts/lifecycle check
  /usr/local/emhttp/plugins/deadlock-guard/scripts/lifecycle upgrade "$$"
fi
upgradepkg --install-new '{package}'
/usr/local/emhttp/plugins/deadlock-guard/scripts/lifecycle install
echo 'Deadlock Guard beta installed. Open Settings → Deadlock Guard and check activation. Reload existing WebGUI tabs.' ''')
 script('''/usr/local/emhttp/plugins/deadlock-guard/scripts/lifecycle remove
rm -f /boot/config/plugins/deadlock-guard/deadlock-guard.cron
/usr/local/sbin/update_cron
removepkg deadlock-guard
echo 'Deadlock Guard removed. Configuration and downloaded packages are retained on the flash drive.' ''',Method='remove')
 ET.indent(root);manifest=b'<?xml version="1.0" encoding="UTF-8"?>\n'+ET.tostring(root,encoding='utf-8')+b'\n';(output/'deadlock-guard.plg').write_bytes(manifest)
 sums=[]
 for p in [output/name,output/'deadlock-guard.plg']:sums.append(hashlib.sha256(p.read_bytes()).hexdigest()+'  '+p.name)
 (output/'SHA256SUMS').write_text('\n'.join(sums)+'\n');print(f'Built {name}: {sha}')
if __name__=='__main__':
 parser=argparse.ArgumentParser();parser.add_argument('--output',type=pathlib.Path,default=ROOT/'dist');parser.add_argument('--update-manifest',action='store_true');args=parser.parse_args();build(args.output)
 if args.update_manifest:(ROOT/'deadlock-guard.plg').write_bytes((args.output/'deadlock-guard.plg').read_bytes())
