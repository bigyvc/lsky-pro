#!/usr/bin/env python3
"""The current adapter requires its controlled rate-limit exception too."""
from pathlib import Path
import runpy
runpy.run_path(str(Path(__file__).with_name('install-rate-limit-retry.py')),run_name='__main__')
