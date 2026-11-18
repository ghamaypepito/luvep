import { Link, usePage } from "@inertiajs/react";
import { ChevronDown, UserRound, Eye } from "lucide-react";

export default function ProfileMenu() {
    const { auth, hr } = usePage().props;
    const user = auth?.user;
    if (!hr?.enabled) return null;
    const ProfileLink = new URL(hr.url, window.location.href).origin === window.location.origin ? Link : "a";
    return (
        <details className="hr-profile-menu">
            <summary
                aria-label="Open profile menu"
                className="hr-profile-trigger"
            >
                {user?.avatar_url ? (
                    <img src={user.avatar_url} alt="" />
                ) : (
                    <span className="hr-avatar">{user?.name?.slice(0, 1)}</span>
                )}
                <span className="hr-menu-name">{user?.name}</span>
                <ChevronDown size={16} />
            </summary>
            <div className="hr-dropdown">
                <ProfileLink href={`${hr.url}profile`}>
                    <UserRound size={16} /> My Info
                </ProfileLink>
                {hr.permissions?.preview && (
                    <ProfileLink href={`${hr.url}preview`}>
                        <Eye size={16} /> Login as employee
                    </ProfileLink>
                )}
            </div>
        </details>
    );
}
