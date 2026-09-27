"""The suite runs as the approved ``testing`` environment with ADR 0053
service keys generated at runtime (tests/service_keys.py) -- set BEFORE
app.main is imported; process environment variables take precedence over
any developer ``.env`` file. No shared service token exists any more."""

import os

from tests.service_keys import GATEWAY_KEY, PLATFORM_KEY, ring

os.environ["ENVIRONMENT"] = "testing"
os.environ["SERVICE_TOKEN"] = ""  # overrides any developer .env value
os.environ["SERVICE_SIGNING_KEY"] = GATEWAY_KEY.private_jwk()
os.environ["PLATFORM_VERIFICATION_KEYS"] = ring(PLATFORM_KEY.public())
