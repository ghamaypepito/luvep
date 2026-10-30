# Sage hover and rounded event cards

User asks for a more complementary hover color and20px event-card corners. Retain dark EP green at rest; use soft sage #dce8d5 in light appearance and #bfd7c1 in dark appearance for hover and keyboard focus. Dark text #193629 and secondary green #3f5948 preserve contrast. All event rows have20px radius. Existing layout, content, data and behaviors remain unchanged.

CSS parse, diff and Impeccable checks pass. Light hover text contrast10.34/6.05; dark hover8.56/5.01, both above4.5. Local light/dark browser rendering inspected, including rest/focus/hover. Computed dark focus background rgb(191,215,193) and20px radius verified. Temporary preview HTML removed before commit. No new tests for this reversible CSS-only refinement.

Deploy only the stylesheet with live/original hashes and private backup; verify live styling and save a public screenshot.
