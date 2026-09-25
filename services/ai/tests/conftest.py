"""Phase 0O.1: the service token has no default and the environment
defaults to "production" (app.core.startup refuses both without explicit
values). The suite runs as the approved ``testing`` environment with the
public development token, set BEFORE app.main is imported -- process
environment variables take precedence over any developer ``.env`` file.
"""

import os

os.environ["ENVIRONMENT"] = "testing"
os.environ["SERVICE_TOKEN"] = "dev-local-only-token"
