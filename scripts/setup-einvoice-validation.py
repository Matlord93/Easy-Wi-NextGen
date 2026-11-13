#!/usr/bin/env python3
"""Install pinned local invoice validators; never runs with customer invoice data."""

import argparse
import hashlib
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import xml.etree.ElementTree as ET
import zipfile

VERSION = 'KoSIT 1.6.3 / XRechnung 3.0.2 2026-08-31 / EN16931 1.3.16 / veraPDF 1.30.4'
ASSETS = {
    'validator.jar': (
        'https://github.com/itplr-kosit/validator/releases/download/v1.6.3/validator-1.6.3-standalone.jar',
        '799e64befca97d4080e03608c80b85dd5a5ecc5f4ae4f35d1116ec2855b9a7c9'),
    'scenarios.zip': (
        'https://github.com/itplr-kosit/validator-configuration-xrechnung/releases/download/v2026-08-31/xrechnung-3.0.2-validator-configuration-2026-08-31.zip',
        '2530cd107c414511c5d0462ec10f886910395abfca820db82e83d70bf01221a8'),
    'verapdf.zip': (
        'https://software.verapdf.org/releases/1.30/verapdf-greenfield-1.30.4-installer.zip',
        '04415ef3f149526a3048b9889723e81320ddf013bd891bcc7f0f297f3eadcc23'),
}


def extract(source, target):
    with zipfile.ZipFile(source) as archive:
        if sum(item.file_size for item in archive.infolist()) > 500_000_000:
            raise ValueError('Oversized validator archive')
        for item in archive.infolist():
            name = Path(item.filename)
            if name.is_absolute() or '..' in name.parts or '\\' in item.filename or (item.external_attr >> 16) & 0o170000 == 0o120000:
                raise ValueError('Unsafe validator archive path')
        archive.extractall(target)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--destination', type=Path, default=Path(__file__).resolve().parents[1] / 'core/var/einvoice-validator')
    parser.add_argument('--asset-cache', type=Path, help='Use previously downloaded, hash-checked archives (offline setup).')
    args = parser.parse_args()
    target = args.destination.resolve()
    if target.exists():
        if (target / 'VERSION').is_file() and (target / 'VERSION').read_text().strip() == VERSION:
            print('Pinned invoice validators are already installed.')
            return
        raise SystemExit('Destination exists. Preserve it and choose a new empty destination.')
    if not shutil.which('java'):
        raise SystemExit('Install a Java 21 runtime first.')
    target.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='.einvoice-setup-', dir=target.parent) as temporary:
        work = Path(temporary)
        staged = work / 'installed'
        (staged / 'kosit').mkdir(parents=True)
        for name, (url, expected) in ASSETS.items():
            destination = work / name
            if args.asset_cache:
                source = args.asset_cache / name
                if not source.is_file() or source.stat().st_size > 120_000_000:
                    raise ValueError('Missing or oversized offline asset: ' + name)
                shutil.copyfile(source, destination)
            else:
                subprocess.run(['curl', '--fail', '--silent', '--show-error', '--location', '--proto', '=https', '--proto-redir', '=https', '--max-time', '180', '--max-filesize', '120000000', '--output', str(destination), url], check=True)
            if hashlib.sha256(destination.read_bytes()).hexdigest() != expected:
                raise ValueError('Validator checksum mismatch: ' + name)
        shutil.copyfile(work / 'validator.jar', staged / 'kosit/validator.jar')
        extract(work / 'scenarios.zip', staged / 'kosit/configuration')
        extract(work / 'verapdf.zip', work / 'installer')
        root = ET.Element('AutomatedInstallation', langpack='eng')
        ET.SubElement(root, 'com.izforge.izpack.panels.htmlhello.HTMLHelloPanel', id='welcome')
        panel = ET.SubElement(root, 'com.izforge.izpack.panels.target.TargetPanel', id='install_dir')
        ET.SubElement(panel, 'installpath').text = str(staged / 'verapdf')
        packs = ET.SubElement(root, 'com.izforge.izpack.panels.packs.PacksPanel', id='sdk_pack_select')
        for index, name in enumerate(['veraPDF Mac and *nix Scripts', 'veraPDF Validation model', 'veraPDF Documentation', 'veraPDF Sample Plugins']):
            ET.SubElement(packs, 'pack', index=str(index), name=name, selected='true' if index < 2 else 'false')
        ET.SubElement(root, 'com.izforge.izpack.panels.install.InstallPanel', id='install')
        ET.SubElement(root, 'com.izforge.izpack.panels.finish.FinishPanel', id='finish')
        ET.ElementTree(root).write(work / 'install.xml', encoding='utf-8', xml_declaration=True)
        jar = work / 'installer/verapdf-greenfield-1.30.4/verapdf-izpack-installer-1.30.4.jar'
        subprocess.run(['java', '-Xmx192m', '-jar', str(jar), str(work / 'install.xml')], check=True, timeout=120, stdout=subprocess.DEVNULL)
        (staged / 'verapdf/verapdf').chmod(0o755)
        (staged / 'VERSION').write_text(VERSION + '\n')
        # Installation is published atomically only after all downloads and checks succeed.
        os.rename(staged, target)
    print('Local invoice validators installed in ' + str(target))


if __name__ == '__main__':
    main()
