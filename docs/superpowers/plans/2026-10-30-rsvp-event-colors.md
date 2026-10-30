# RSVP event card colors

User asks for darker green event choices at rest and the existing pale green on hover. Preserve the EP design, page layout, event schedule, form and automatic appearance. Add scoped event-state tokens, high contrast tinted text at rest, dark text on the pale interaction state, and matching keyboard focus. Limit pointer hover to devices that support it; preserve reduced motion and forced colors.

CSS syntax parse and diff whitespace checks pass. Impeccable detector completed without findings. Measured contrast ratios for primary and secondary text: light resting state 7.63/6.28, dark resting state 7.79/6.41, light interaction 11.73/5.48, dark interaction 9.82/4.59. Local forced light and dark browser views inspected; keyboard focus exercises the same variable mapping as hover. Actual computed dark colors match the tokens. Local preview files removed before commit. No unit tests added for this reversible CSS-only change.

Deploy only the stylesheet with original/live hash check, atomic replacement and private source backup. Verify live event list plus pale keyboard interaction, save public screenshot; no attendance submission or outreach action.
