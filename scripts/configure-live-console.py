#!/usr/bin/env python3
"""Source-checkout entry point; the self-contained helper ships inside core/."""
import importlib.util
from pathlib import Path

path = Path(__file__).resolve().parent.parent / 'core/tools/console/configure-live-console.py'
spec = importlib.util.spec_from_file_location('easywi_live_console_config', path)
helper = importlib.util.module_from_spec(spec)
spec.loader.exec_module(helper)
configure = helper.configure
env_value = helper.env_value

if __name__ == '__main__':
    helper.main()
