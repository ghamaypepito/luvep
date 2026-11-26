import React from 'react';

function calendarDate(value) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value || '')) return null;
    const date = new Date(`${value}T00:00:00Z`);
    if (!Number.isFinite(date.getTime()) || date.toISOString().slice(0, 10) !== value) return null;
    return value.split('-').map(Number);
}

export function ageFromBirthDate(dob, referenceDate) {
    const birth = calendarDate(dob);
    const today = calendarDate(referenceDate);
    if (!birth || !today || dob > referenceDate) return null;
    const [year, month, day] = today;
    return year - birth[0] - (month < birth[1] || (month === birth[1] && day < birth[2]) ? 1 : 0);
}

export default function EducationAge({ dob, referenceDate, fallbackAge = null }) {
    // Preserve imported age only when the record has no DOB. Never use a
    // stale saved age in place of an invalid or newly edited birthdate.
    const age = dob ? ageFromBirthDate(dob, referenceDate)
        : (fallbackAge !== '' && fallbackAge != null && Number.isInteger(Number(fallbackAge)) && Number(fallbackAge) >= 0 ? Number(fallbackAge) : null);
    return <div className="flex flex-col gap-1">
        <span id="education-profile-age-label" className="text-xs font-semibold text-gray-500 tracking-wide uppercase">Age</span>
        <output aria-labelledby="education-profile-age-label" aria-live="polite" className="text-sm font-medium text-gray-900 bg-gray-50/50 px-3 py-2 rounded-lg border border-gray-100">{age ?? '-'}</output>
    </div>;
}
