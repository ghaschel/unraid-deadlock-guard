import hashlib, pathlib, subprocess, tarfile, tempfile, unittest, xml.etree.ElementTree as ET
ROOT=pathlib.Path(__file__).resolve().parents[1]
class PackageTest(unittest.TestCase):
 def test_release_is_reproducible_and_manifest_matches(self):
  with tempfile.TemporaryDirectory() as a, tempfile.TemporaryDirectory() as b:
   for out in [a,b]: subprocess.run(['python3','scripts/build.py','--output',out],cwd=ROOT,check=True)
   p=next(pathlib.Path(a).glob('*.txz'));q=pathlib.Path(b)/p.name;self.assertEqual(p.read_bytes(),q.read_bytes())
   manifest=ET.parse(pathlib.Path(a)/'deadlock-guard.plg').getroot();wrapper=ET.parse(ROOT/'plugins/deadlock-guard.xml').getroot()
   self.assertEqual(manifest.attrib['pluginURL'],wrapper.findtext('PluginURL'));self.assertEqual(manifest.attrib['min'],'7.3.0')
   self.assertIn(hashlib.sha256(p.read_bytes()).hexdigest(),(pathlib.Path(a)/'deadlock-guard.plg').read_text())
   with tarfile.open(p) as tar:
    names=tar.getnames();self.assertTrue(any(x.endswith('/scripts/qemu-hook') for x in names));self.assertNotIn('boot/config/plugins/deadlock-guard/config.json',names)
    self.assertTrue(all(not n.startswith('/') and '..' not in pathlib.PurePosixPath(n).parts for n in names));self.assertTrue(all(m.uid==m.gid==0 for m in tar))
   self.assertTrue(ET.parse(ROOT/'ca_profile.xml').findtext('Profile').strip())
if __name__=='__main__':unittest.main()
