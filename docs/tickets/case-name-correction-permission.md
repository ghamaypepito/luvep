# Scoped Immigration name correction

Existing Admin/Super Admin name correction remains available. An active Immigration manager may additionally receive the per-user **Name Correction — Immigration** option through existing Super Admin Module Management. It is off for managers by default; no role, portal or production account is changed by deployment.

The option permits correcting applicant and dependant names within existing authorized case-edit paths. The shared case-name boundary and the Personal/Cases edit controls use the same server capability. Case archiving, archived-record operations, role management and other Admin access keep their separate existing checks. Other roles, inactive accounts, ungranted managers, unknown grant keys and View-as previews do not acquire this capability.

Grant/revocation changes are audited with the target account and prior/new flag. Applicant edits retain existing Lead activity auditing. Dependant name changes receive a case-scoped before/after audit in the same transaction. Revocation takes effect on the next authenticated request. No automatic client name change, assessment overwrite, passport processing, case conversion or document action is added.

Synthetic tests exercise the real Module Management grant/revoke flow, actual applicant/dependant updates, default/role/preview denial, case isolation, retained unrelated permissions and unchanged archive authority. Actual React checks cover the Personal form and Cases edit modal with granted/revoked capabilities and prove rendering never submits a change. Required independent testing, separate review, exact-head CI and live checks precede closure. Production activation is a separate explicit per-user grant through the normal management UI.
