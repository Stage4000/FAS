# Embedded manual table of contents

The reader offers a native disclosure listing the PDF's own embedded sections. It is fetched only on first opening after document readiness. It is not an automatically generated or OCR-derived table of contents.

Only internal destinations are supported: zero-based page indices, PDF page references and named PDF destinations. External URLs, unsafe URLs and named actions are never followed. Each destination is resolved only when selected and must resolve to a valid page in the current document. Headings use textContent rather than HTML. Existing renderer, download, rights, storage and release controls are unchanged.

The retained navigation index is bounded to 500 entries, 12 levels and 200 characters per heading, with explicit omission guidance. Named destination labels are limited to 1,024 characters; destination arrays retain only the page target. Raw outline trees are not retained by this UI after normalization. PDF.js must still parse the source outline; these bounds are not a worker-memory or parsing-work guarantee.

Closing the disclosure, newer selections, intervening page rendering and permanent departure prevent older destination responses from navigating. Empty, unsupported and failed outlines retain page-number navigation and document search. Reopening reuses the index; failed extraction does not create an automatic retry loop.

Local tests cover model limits, destination validation and the actual initialize path using a controlled DOM and synthetic PDF interface. This is not real-browser, assistive-device, production or actual-manual acceptance. Public release and import holds remain in place.
