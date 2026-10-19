import { hrPath } from "@/lib/hrPath";
import { Head, useForm } from "@inertiajs/react";
import { Errors } from "./Dtr";
function Account({ account, abilities, actorId }) {
    const f = useForm({ version: account.version, grants: account.grants });
    const added = f.data.grants.filter((g) => !account.grants.includes(g));
    const removed = account.grants.filter((g) => !f.data.grants.includes(g));
    return (
        <details className="hr-panel">
            <summary>
                <strong>{account.name}</strong> ·{" "}
                {account.role.replaceAll("_", " ")} ·{" "}
                {account.active ? "Active" : "Inactive"}
            </summary>
            {!account.editable ? (
                <p>Super Admin access is provided by the account role.</p>
            ) : (
                <form
                    className="hr-form"
                    onSubmit={(e) => {
                        e.preventDefault();
                        f.post(hrPath(`/access/${account.id}`), {
                            preserveScroll: true,
                        });
                    }}
                >
                    <Errors errors={f.errors} />
                    <p>
                        These permissions apply across HR. Department Head and
                        DTR reviewer assignments are configured in People.
                        Existing role permissions remain in effect.
                    </p>
                    <div className="hr-permission-grid">
                        {Object.entries(abilities).map(([key, label]) => {
                            const checked = f.data.grants.includes(key);
                            const inherited =
                                account.effective.includes(key) &&
                                !account.grants.includes(key);
                            return (
                                <label key={key}>
                                    <input
                                        type="checkbox"
                                        checked={checked}
                                        disabled={
                                            !account.active ||
                                            (account.id === actorId &&
                                                !account.grants.includes(key))
                                        }
                                        onChange={(e) =>
                                            f.setData(
                                                "grants",
                                                e.target.checked
                                                    ? [...f.data.grants, key]
                                                    : f.data.grants.filter(
                                                          (x) => x !== key,
                                                      ),
                                            )
                                        }
                                    />
                                    <span>
                                        {label}
                                        {inherited && (
                                            <small>
                                                {" "}
                                                · Already provided by role
                                            </small>
                                        )}
                                    </span>
                                </label>
                            );
                        })}
                    </div>
                    {!account.active && (
                        <button
                            type="button"
                            className="hr-button-secondary"
                            onClick={() => f.setData("grants", [])}
                        >
                            Clear inactive account grants
                        </button>
                    )}
                    <div className="hr-panel">
                        <strong>Changes for {account.name}</strong>
                        <p>
                            Grant:{" "}
                            {added.map((g) => abilities[g]).join(", ") ||
                                "None"}
                        </p>
                        <p>
                            Remove explicit grant:{" "}
                            {removed.map((g) => abilities[g]).join(", ") ||
                                "None"}
                        </p>
                    </div>
                    <div>
                        <button
                            className="hr-button"
                            disabled={
                                f.processing ||
                                (!added.length && !removed.length)
                            }
                        >
                            Save access
                        </button>
                    </div>
                </form>
            )}
        </details>
    );
}
export default function Access({ accounts, abilities, actorId }) {
    return (
        <>
            <Head title="HR access · Luvep" />
            <div className="hr-page-heading">
                <h1>Access administration</h1>
                <p>
                    Grant the exact access each colleague needs. Confidential
                    document classes require their own permission.
                </p>
            </div>
            {accounts.map((a) => (
                <Account
                    key={`${a.id}:${a.version}`}
                    account={a}
                    abilities={abilities}
                    actorId={actorId}
                />
            ))}
        </>
    );
}
