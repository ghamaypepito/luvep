import { hrPath } from "@/lib/hrPath";
import { Head, Link, router, useForm, usePage } from "@inertiajs/react";

export default function Profile({ profile, readOnly = false }) {
    const { hr } = usePage().props;
    const locked = readOnly || !!hr?.preview;
    const photo = useForm({ avatar: null });
    const form = useForm({
        phone: profile.user?.phone || "",
        emergency_contact: profile.emergency_contact || "",
    });
    const submit = (event) => {
        event.preventDefault();
        if (!locked) form.patch(hrPath("/profile"), { preserveScroll: true });
    };
    return (
        <>
            <Head title="My profile · Luvep" />
            <div className="hr-page-heading">
                <h1>{readOnly ? "Employee profile" : "My profile"}</h1>
                <p>Your details, securely held in one place.</p>
                {readOnly &&
                    !hr.preview &&
                    hr.permissions["profile.manage"] && (
                        <Link
                            className="hr-button-secondary"
                            href={hrPath(`/people/${profile.id}/edit`)}
                        >
                            Edit employee setup
                        </Link>
                    )}
            </div>
            <section className="hr-panel">
                <div className="hr-person-heading">
                    <span className="hr-avatar hr-avatar-large">
                        {profile.user.avatar_url ? (
                            <img src={profile.user.avatar_url} alt="" />
                        ) : (
                            profile.user.name?.slice(0, 1)
                        )}
                    </span>
                    <div>
                        <h2>{profile.user.name}</h2>
                        <p>{profile.user.email}</p>
                    </div>
                </div>
                <dl className="hr-profile-grid">
                    {[
                        ["Employee ID", profile.employee_number],
                        ["Department", profile.department],
                        ["Position", profile.position],
                        ["Office", profile.office],
                        ["Employment type", profile.employment_type],
                        ["Manager", profile.manager],
                        ["Operations reviewer", profile.reviewer],
                        ["Time zone", profile.schedule?.timezone],
                    ].map(([label, value]) => (
                        <div key={label}>
                            <dt>{label}</dt>
                            <dd>{value || "Awaiting HR setup"}</dd>
                        </div>
                    ))}
                </dl>
            </section>
            {!locked && (
                <section className="hr-panel">
                    <h2>Profile photo</h2>
                    <form
                        className="hr-form"
                        onSubmit={(e) => {
                            e.preventDefault();
                            photo.post(hrPath("/profile/avatar"), {
                                forceFormData: true,
                                preserveScroll: true,
                            });
                        }}
                    >
                        <label>
                            Choose a photo
                            <input
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                onChange={(e) =>
                                    photo.setData(
                                        "avatar",
                                        e.target.files[0] || null,
                                    )
                                }
                            />
                        </label>
                        {photo.errors.avatar && (
                            <p className="hr-error" role="alert">
                                {photo.errors.avatar}
                            </p>
                        )}
                        <div className="hr-actions">
                            <button
                                className="hr-button-secondary"
                                disabled={
                                    !photo.data.avatar || photo.processing
                                }
                            >
                                Update photo
                            </button>
                            {profile.user.avatar_url && (
                                <button
                                    type="button"
                                    className="hr-button-secondary"
                                    onClick={() =>
                                        router.delete(hrPath("/profile/avatar"), {
                                            preserveScroll: true,
                                        })
                                    }
                                >
                                    Remove photo
                                </button>
                            )}
                        </div>
                    </form>
                </section>
            )}
            <section className="hr-panel">
                <h2>Contact information</h2>
                <p>Employment details are managed by HR.</p>
                <form onSubmit={submit} className="hr-form">
                    <label>
                        Phone number
                        <input
                            type="tel"
                            value={form.data.phone}
                            disabled={locked || form.processing}
                            onChange={(e) =>
                                form.setData("phone", e.target.value)
                            }
                            autoComplete="tel"
                        />
                    </label>
                    {form.errors.phone && (
                        <p className="hr-error" role="alert">
                            {form.errors.phone}
                        </p>
                    )}
                    {"emergency_contact" in profile && (
                        <label>
                            Emergency contact
                            <textarea
                                value={form.data.emergency_contact}
                                disabled={locked || form.processing}
                                onChange={(e) =>
                                    form.setData(
                                        "emergency_contact",
                                        e.target.value,
                                    )
                                }
                                maxLength={1000}
                                rows={3}
                            />
                            <span>
                                Contact name, relationship and phone number.
                            </span>
                        </label>
                    )}
                    {form.errors.emergency_contact && (
                        <p className="hr-error" role="alert">
                            {form.errors.emergency_contact}
                        </p>
                    )}
                    {!locked && (
                        <div>
                            <button
                                className="hr-button"
                                disabled={form.processing}
                            >
                                {form.processing ? "Saving…" : "Save details"}
                            </button>
                            {form.recentlySuccessful && (
                                <span role="status" className="hr-saved">
                                    Saved
                                </span>
                            )}
                        </div>
                    )}
                </form>
            </section>
        </>
    );
}
