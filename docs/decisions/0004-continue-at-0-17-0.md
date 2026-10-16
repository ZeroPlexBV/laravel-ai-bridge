---
status: accepted
date: 2026-10-03
supersedes:
superseded_by:
revisit_when:
---
# After merging the ZeroPlex fork's 0.16, numbering continues at 0.17.0

## Context

The ZeroPlexBV fork published 0.16.0-RC1, 0.16.0 and 0.16.1 while its changes waited to go
upstream. Upstream had its own 0.16.0 (the desired bridge version, PR #38). PR #39 merged the fork
back with its history and authorship intact.

## Decision

The two 0.16.x lines are different releases sharing version numbers, so the merged package
continues at 0.17.0 (tagged 2026-10-03). The fork's release notes stay in CHANGELOG.md under their
own versions, marked as published from the fork.

## Consequences

- `0.16.0` means different code depending on where it was installed from; anyone on either line
  moves to 0.17.0 or later.
