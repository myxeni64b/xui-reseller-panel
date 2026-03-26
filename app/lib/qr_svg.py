#!/usr/bin/env python3
from __future__ import annotations
import io
import os
import sys

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
VENDOR_DIR = os.path.join(BASE_DIR, 'vendor_qrcode')
if VENDOR_DIR not in sys.path:
    sys.path.insert(0, VENDOR_DIR)

import qrcode
from qrcode.image.svg import SvgImage
from qrcode.constants import ERROR_CORRECT_M


def main():
    data = sys.stdin.read()
    if not data:
        sys.stderr.write('no-data')
        return 2
    qr = qrcode.QRCode(error_correction=ERROR_CORRECT_M, box_size=6, border=2, image_factory=SvgImage)
    qr.add_data(data)
    qr.make(fit=True)
    image = qr.make_image()
    buffer = io.BytesIO()
    image.save(buffer)
    sys.stdout.write(buffer.getvalue().decode('utf-8'))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
