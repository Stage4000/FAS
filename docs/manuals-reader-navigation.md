# Reader input/render race correction

Hosted run 43164044509 on ba8aca7f passed the strict fixture gates and 15 of 16 real Chromium reader cases. Rapid navigation timed out with the cover still visible.

A deterministic DOM/PDF unit reproduction establishes one real race: after a user types a newer page number but before submitting Go, a previous render can complete and overwrite the input with its own page. The fix records input edits and preserves the unsubmitted value until a valid page submission consumes it or a newer explicit page navigation supersedes it. Background repaint/resize and zoom preserve the draft. Render state, displayed pages, hash and accessible text continue to commit only after successful rendering. No animation or rendering assertion was relaxed.

The new six-check regression fails the original source at the overwritten input assertion and passes the correction. It is a controlled DOM/PDF stub test, not browser acceptance. Existing 257 helper/recovery/search checks pass locally. The unchanged rapid navigation browser case must pass on the exact published head before claiming the hosted failure resolved.

No production change, main merge, public release, real-manual import or change to isolation restrictions is included.
