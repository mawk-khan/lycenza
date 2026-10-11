"""E03.1 (ADR 0072): repository-side publication control for the `CI gate`.

Standard library only. Nothing here pushes, configures GitHub or holds a
credential; `publication` only refuses or allows the owner's own fast-forward.
"""
