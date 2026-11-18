# Staff access to client pathways — TKT-TFTVGN

Preserve existing Add pathway workflow, source data and department boundaries. Give Emma Ceballo and Nova Santillan an individually managed grant for adding any available pathway from new leads. Label the education destination Student and expose a shortcut in the existing List of Leads row menu.

1. Add regression coverage for granted Education/Immigration Manager accounts across all destinations and denial boundaries (ungranted, inactive, external, unrelated/implicitly active clients).
2. Add restricted client_pathways module; allow explicitly granted staff from lead-handling departments to manage unconverted leads. Preserve existing own-department access and cross-portal restrictions.
3. Reuse the existing dialog from the shared lead row menu. Verify interaction, destination selection, close/focus and server error handling.
4. Run focused and full regression/build, review diff, merge via passing GitHub CI and verify Actions deployment.
5. Append only this module permission to the two uniquely matched active production accounts in a transaction with an audit record. Do not change roles/portal grants or move real clients. Verify effective permissions read-only and record the ticket resolution for requester testing.
