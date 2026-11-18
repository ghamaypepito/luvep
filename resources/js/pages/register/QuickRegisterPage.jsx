import { useEffect, useRef, useState } from "react";
import { useForm, Head } from "@inertiajs/react";
import { Upload, FileText, X, CheckCircle, ArrowRight, ChevronDown, Search } from "lucide-react";
import Navbar from "@/components/layout/Navbar";
import Footer from "@/components/layout/Footer";
import heroBg from "@assets/banner/register-ep.webp";

// Standalone registration page — collects a richer applicant profile so the
// team can assess + prepare a proposal before the consultation. Posts to
// /register (storeRegistration). The detailed assessment-style version lives
// at /register/full.

const GENDERS = ['Male', 'Female'];
const CIVIL_STATUSES = ['Single', 'Married', 'Widowed', 'Separated/Divorced', 'Single with Partner'];
const ATTAINMENTS = [
    'Doctorate (PhD / EdD / DBA)',
    "Master's Degree",
    'Postgraduate Diploma / Certificate',
    "Bachelor's Degree",
    'Associate Degree',
    'Technical-Vocational (TESDA / TVET)',
    'High School Graduate',
    'Other',
];
const PATHWAYS = ['Study + Work Pathways', 'Work Pathway', 'Other'];
const BRING_CHILDREN = ['Yes', 'No', 'Other'];

// Well-known email domains + TLDs used to catch typos like "gmil.com" or
// "yaho.co". A tiny Levenshtein check flags anything close-but-not-equal
// so we can offer a one-click correction inline.
const KNOWN_EMAIL_DOMAINS = [
    'gmail.com', 'yahoo.com', 'outlook.com', 'hotmail.com', 'icloud.com',
    'live.com', 'aol.com', 'proton.me', 'protonmail.com', 'msn.com',
    'me.com', 'mail.com', 'yandex.com', 'zoho.com', 'gmx.com',
    // NZ / PH specifics — high-traffic corporate / edu domains.
    'xtra.co.nz', 'yahoo.co.nz', 'gmail.co.nz',
    'yahoo.com.ph', 'yahoo.ph',
];

function levenshtein(a, b) {
    if (a === b) return 0;
    if (! a.length) return b.length;
    if (! b.length) return a.length;
    const m = Array.from({ length: a.length + 1 }, (_, i) => [i]);
    for (let j = 0; j <= b.length; j++) m[0][j] = j;
    for (let i = 1; i <= a.length; i++) {
        for (let j = 1; j <= b.length; j++) {
            const cost = a[i - 1] === b[j - 1] ? 0 : 1;
            m[i][j] = Math.min(m[i - 1][j] + 1, m[i][j - 1] + 1, m[i - 1][j - 1] + cost);
        }
    }
    return m[a.length][b.length];
}

/**
 * Inspect an email string and return either { ok: true } when it looks
 * clean, { error: '…' } when the format is broken, or { suggest: 'x@…' }
 * when the domain is a typo of a known provider.
 */
function inspectEmail(raw) {
    const email = (raw || '').trim().toLowerCase();
    if (! email) return { ok: true };
    const at = email.lastIndexOf('@');
    if (at < 1 || at === email.length - 1) {
        return { error: 'Email is missing an "@" or domain.' };
    }
    const local = email.slice(0, at);
    const domain = email.slice(at + 1);
    if (! domain.includes('.')) {
        return { error: 'Email domain looks incomplete (missing ".").' };
    }
    if (KNOWN_EMAIL_DOMAINS.includes(domain)) return { ok: true };

    // Suggest the closest known domain within 2 edits so "gmil.com" →
    // "gmail.com", but a bona fide corporate address like "jane@acme.co"
    // is left alone (distance to every known domain is > 2).
    let best = null;
    let bestDist = Infinity;
    for (const known of KNOWN_EMAIL_DOMAINS) {
        const d = levenshtein(domain, known);
        if (d < bestDist) { bestDist = d; best = known; }
    }
    if (best && bestDist > 0 && bestDist <= 2) {
        return { suggest: `${local}@${best}` };
    }
    return { ok: true };
}

// Comprehensive ITU-T dialling code list — every UN member + common
// territories. NZ + PH are pinned at the top (primary markets); the
// rest are sorted alphabetically by country label. Each entry:
// { iso, dial, label }.
const COUNTRY_CODES = (() => {
    const pinned = [
        { iso: 'NZ', dial: '+64', label: 'New Zealand' },
        { iso: 'PH', dial: '+63', label: 'Philippines' },
    ];
    const rest = [
        { iso: 'AF', dial: '+93',   label: 'Afghanistan' },
        { iso: 'AL', dial: '+355',  label: 'Albania' },
        { iso: 'DZ', dial: '+213',  label: 'Algeria' },
        { iso: 'AS', dial: '+1684', label: 'American Samoa' },
        { iso: 'AD', dial: '+376',  label: 'Andorra' },
        { iso: 'AO', dial: '+244',  label: 'Angola' },
        { iso: 'AI', dial: '+1264', label: 'Anguilla' },
        { iso: 'AG', dial: '+1268', label: 'Antigua & Barbuda' },
        { iso: 'AR', dial: '+54',   label: 'Argentina' },
        { iso: 'AM', dial: '+374',  label: 'Armenia' },
        { iso: 'AW', dial: '+297',  label: 'Aruba' },
        { iso: 'AU', dial: '+61',   label: 'Australia' },
        { iso: 'AT', dial: '+43',   label: 'Austria' },
        { iso: 'AZ', dial: '+994',  label: 'Azerbaijan' },
        { iso: 'BS', dial: '+1242', label: 'Bahamas' },
        { iso: 'BH', dial: '+973',  label: 'Bahrain' },
        { iso: 'BD', dial: '+880',  label: 'Bangladesh' },
        { iso: 'BB', dial: '+1246', label: 'Barbados' },
        { iso: 'BY', dial: '+375',  label: 'Belarus' },
        { iso: 'BE', dial: '+32',   label: 'Belgium' },
        { iso: 'BZ', dial: '+501',  label: 'Belize' },
        { iso: 'BJ', dial: '+229',  label: 'Benin' },
        { iso: 'BM', dial: '+1441', label: 'Bermuda' },
        { iso: 'BT', dial: '+975',  label: 'Bhutan' },
        { iso: 'BO', dial: '+591',  label: 'Bolivia' },
        { iso: 'BA', dial: '+387',  label: 'Bosnia & Herzegovina' },
        { iso: 'BW', dial: '+267',  label: 'Botswana' },
        { iso: 'BR', dial: '+55',   label: 'Brazil' },
        { iso: 'IO', dial: '+246',  label: 'British Indian Ocean Territory' },
        { iso: 'VG', dial: '+1284', label: 'British Virgin Islands' },
        { iso: 'BN', dial: '+673',  label: 'Brunei' },
        { iso: 'BG', dial: '+359',  label: 'Bulgaria' },
        { iso: 'BF', dial: '+226',  label: 'Burkina Faso' },
        { iso: 'BI', dial: '+257',  label: 'Burundi' },
        { iso: 'KH', dial: '+855',  label: 'Cambodia' },
        { iso: 'CM', dial: '+237',  label: 'Cameroon' },
        { iso: 'CA', dial: '+1',    label: 'Canada' },
        { iso: 'CV', dial: '+238',  label: 'Cape Verde' },
        { iso: 'KY', dial: '+1345', label: 'Cayman Islands' },
        { iso: 'CF', dial: '+236',  label: 'Central African Republic' },
        { iso: 'TD', dial: '+235',  label: 'Chad' },
        { iso: 'CL', dial: '+56',   label: 'Chile' },
        { iso: 'CN', dial: '+86',   label: 'China' },
        { iso: 'CX', dial: '+61',   label: 'Christmas Island' },
        { iso: 'CC', dial: '+61',   label: 'Cocos (Keeling) Islands' },
        { iso: 'CO', dial: '+57',   label: 'Colombia' },
        { iso: 'KM', dial: '+269',  label: 'Comoros' },
        { iso: 'CG', dial: '+242',  label: 'Congo — Brazzaville' },
        { iso: 'CD', dial: '+243',  label: 'Congo — Kinshasa (DRC)' },
        { iso: 'CK', dial: '+682',  label: 'Cook Islands' },
        { iso: 'CR', dial: '+506',  label: 'Costa Rica' },
        { iso: 'CI', dial: '+225',  label: "Côte d'Ivoire" },
        { iso: 'HR', dial: '+385',  label: 'Croatia' },
        { iso: 'CU', dial: '+53',   label: 'Cuba' },
        { iso: 'CW', dial: '+599',  label: 'Curaçao' },
        { iso: 'CY', dial: '+357',  label: 'Cyprus' },
        { iso: 'CZ', dial: '+420',  label: 'Czechia' },
        { iso: 'DK', dial: '+45',   label: 'Denmark' },
        { iso: 'DJ', dial: '+253',  label: 'Djibouti' },
        { iso: 'DM', dial: '+1767', label: 'Dominica' },
        { iso: 'DO', dial: '+1809', label: 'Dominican Republic' },
        { iso: 'EC', dial: '+593',  label: 'Ecuador' },
        { iso: 'EG', dial: '+20',   label: 'Egypt' },
        { iso: 'SV', dial: '+503',  label: 'El Salvador' },
        { iso: 'GQ', dial: '+240',  label: 'Equatorial Guinea' },
        { iso: 'ER', dial: '+291',  label: 'Eritrea' },
        { iso: 'EE', dial: '+372',  label: 'Estonia' },
        { iso: 'SZ', dial: '+268',  label: 'Eswatini' },
        { iso: 'ET', dial: '+251',  label: 'Ethiopia' },
        { iso: 'FK', dial: '+500',  label: 'Falkland Islands' },
        { iso: 'FO', dial: '+298',  label: 'Faroe Islands' },
        { iso: 'FJ', dial: '+679',  label: 'Fiji' },
        { iso: 'FI', dial: '+358',  label: 'Finland' },
        { iso: 'FR', dial: '+33',   label: 'France' },
        { iso: 'GF', dial: '+594',  label: 'French Guiana' },
        { iso: 'PF', dial: '+689',  label: 'French Polynesia' },
        { iso: 'GA', dial: '+241',  label: 'Gabon' },
        { iso: 'GM', dial: '+220',  label: 'Gambia' },
        { iso: 'GE', dial: '+995',  label: 'Georgia' },
        { iso: 'DE', dial: '+49',   label: 'Germany' },
        { iso: 'GH', dial: '+233',  label: 'Ghana' },
        { iso: 'GI', dial: '+350',  label: 'Gibraltar' },
        { iso: 'GR', dial: '+30',   label: 'Greece' },
        { iso: 'GL', dial: '+299',  label: 'Greenland' },
        { iso: 'GD', dial: '+1473', label: 'Grenada' },
        { iso: 'GP', dial: '+590',  label: 'Guadeloupe' },
        { iso: 'GU', dial: '+1671', label: 'Guam' },
        { iso: 'GT', dial: '+502',  label: 'Guatemala' },
        { iso: 'GG', dial: '+44',   label: 'Guernsey' },
        { iso: 'GN', dial: '+224',  label: 'Guinea' },
        { iso: 'GW', dial: '+245',  label: 'Guinea-Bissau' },
        { iso: 'GY', dial: '+592',  label: 'Guyana' },
        { iso: 'HT', dial: '+509',  label: 'Haiti' },
        { iso: 'HN', dial: '+504',  label: 'Honduras' },
        { iso: 'HK', dial: '+852',  label: 'Hong Kong' },
        { iso: 'HU', dial: '+36',   label: 'Hungary' },
        { iso: 'IS', dial: '+354',  label: 'Iceland' },
        { iso: 'IN', dial: '+91',   label: 'India' },
        { iso: 'ID', dial: '+62',   label: 'Indonesia' },
        { iso: 'IR', dial: '+98',   label: 'Iran' },
        { iso: 'IQ', dial: '+964',  label: 'Iraq' },
        { iso: 'IE', dial: '+353',  label: 'Ireland' },
        { iso: 'IM', dial: '+44',   label: 'Isle of Man' },
        { iso: 'IL', dial: '+972',  label: 'Israel' },
        { iso: 'IT', dial: '+39',   label: 'Italy' },
        { iso: 'JM', dial: '+1876', label: 'Jamaica' },
        { iso: 'JP', dial: '+81',   label: 'Japan' },
        { iso: 'JE', dial: '+44',   label: 'Jersey' },
        { iso: 'JO', dial: '+962',  label: 'Jordan' },
        { iso: 'KZ', dial: '+7',    label: 'Kazakhstan' },
        { iso: 'KE', dial: '+254',  label: 'Kenya' },
        { iso: 'KI', dial: '+686',  label: 'Kiribati' },
        { iso: 'XK', dial: '+383',  label: 'Kosovo' },
        { iso: 'KW', dial: '+965',  label: 'Kuwait' },
        { iso: 'KG', dial: '+996',  label: 'Kyrgyzstan' },
        { iso: 'LA', dial: '+856',  label: 'Laos' },
        { iso: 'LV', dial: '+371',  label: 'Latvia' },
        { iso: 'LB', dial: '+961',  label: 'Lebanon' },
        { iso: 'LS', dial: '+266',  label: 'Lesotho' },
        { iso: 'LR', dial: '+231',  label: 'Liberia' },
        { iso: 'LY', dial: '+218',  label: 'Libya' },
        { iso: 'LI', dial: '+423',  label: 'Liechtenstein' },
        { iso: 'LT', dial: '+370',  label: 'Lithuania' },
        { iso: 'LU', dial: '+352',  label: 'Luxembourg' },
        { iso: 'MO', dial: '+853',  label: 'Macau' },
        { iso: 'MG', dial: '+261',  label: 'Madagascar' },
        { iso: 'MW', dial: '+265',  label: 'Malawi' },
        { iso: 'MY', dial: '+60',   label: 'Malaysia' },
        { iso: 'MV', dial: '+960',  label: 'Maldives' },
        { iso: 'ML', dial: '+223',  label: 'Mali' },
        { iso: 'MT', dial: '+356',  label: 'Malta' },
        { iso: 'MH', dial: '+692',  label: 'Marshall Islands' },
        { iso: 'MQ', dial: '+596',  label: 'Martinique' },
        { iso: 'MR', dial: '+222',  label: 'Mauritania' },
        { iso: 'MU', dial: '+230',  label: 'Mauritius' },
        { iso: 'YT', dial: '+262',  label: 'Mayotte' },
        { iso: 'MX', dial: '+52',   label: 'Mexico' },
        { iso: 'FM', dial: '+691',  label: 'Micronesia' },
        { iso: 'MD', dial: '+373',  label: 'Moldova' },
        { iso: 'MC', dial: '+377',  label: 'Monaco' },
        { iso: 'MN', dial: '+976',  label: 'Mongolia' },
        { iso: 'ME', dial: '+382',  label: 'Montenegro' },
        { iso: 'MS', dial: '+1664', label: 'Montserrat' },
        { iso: 'MA', dial: '+212',  label: 'Morocco' },
        { iso: 'MZ', dial: '+258',  label: 'Mozambique' },
        { iso: 'MM', dial: '+95',   label: 'Myanmar' },
        { iso: 'NA', dial: '+264',  label: 'Namibia' },
        { iso: 'NR', dial: '+674',  label: 'Nauru' },
        { iso: 'NP', dial: '+977',  label: 'Nepal' },
        { iso: 'NL', dial: '+31',   label: 'Netherlands' },
        { iso: 'NC', dial: '+687',  label: 'New Caledonia' },
        { iso: 'NI', dial: '+505',  label: 'Nicaragua' },
        { iso: 'NE', dial: '+227',  label: 'Niger' },
        { iso: 'NG', dial: '+234',  label: 'Nigeria' },
        { iso: 'NU', dial: '+683',  label: 'Niue' },
        { iso: 'NF', dial: '+672',  label: 'Norfolk Island' },
        { iso: 'KP', dial: '+850',  label: 'North Korea' },
        { iso: 'MK', dial: '+389',  label: 'North Macedonia' },
        { iso: 'MP', dial: '+1670', label: 'Northern Mariana Islands' },
        { iso: 'NO', dial: '+47',   label: 'Norway' },
        { iso: 'OM', dial: '+968',  label: 'Oman' },
        { iso: 'PK', dial: '+92',   label: 'Pakistan' },
        { iso: 'PW', dial: '+680',  label: 'Palau' },
        { iso: 'PS', dial: '+970',  label: 'Palestine' },
        { iso: 'PA', dial: '+507',  label: 'Panama' },
        { iso: 'PG', dial: '+675',  label: 'Papua New Guinea' },
        { iso: 'PY', dial: '+595',  label: 'Paraguay' },
        { iso: 'PE', dial: '+51',   label: 'Peru' },
        { iso: 'PL', dial: '+48',   label: 'Poland' },
        { iso: 'PT', dial: '+351',  label: 'Portugal' },
        { iso: 'PR', dial: '+1787', label: 'Puerto Rico' },
        { iso: 'QA', dial: '+974',  label: 'Qatar' },
        { iso: 'RE', dial: '+262',  label: 'Réunion' },
        { iso: 'RO', dial: '+40',   label: 'Romania' },
        { iso: 'RU', dial: '+7',    label: 'Russia' },
        { iso: 'RW', dial: '+250',  label: 'Rwanda' },
        { iso: 'BL', dial: '+590',  label: 'Saint Barthélemy' },
        { iso: 'SH', dial: '+290',  label: 'Saint Helena' },
        { iso: 'KN', dial: '+1869', label: 'Saint Kitts & Nevis' },
        { iso: 'LC', dial: '+1758', label: 'Saint Lucia' },
        { iso: 'MF', dial: '+590',  label: 'Saint Martin (French)' },
        { iso: 'PM', dial: '+508',  label: 'Saint Pierre & Miquelon' },
        { iso: 'VC', dial: '+1784', label: 'Saint Vincent & Grenadines' },
        { iso: 'WS', dial: '+685',  label: 'Samoa' },
        { iso: 'SM', dial: '+378',  label: 'San Marino' },
        { iso: 'ST', dial: '+239',  label: 'São Tomé & Príncipe' },
        { iso: 'SA', dial: '+966',  label: 'Saudi Arabia' },
        { iso: 'SN', dial: '+221',  label: 'Senegal' },
        { iso: 'RS', dial: '+381',  label: 'Serbia' },
        { iso: 'SC', dial: '+248',  label: 'Seychelles' },
        { iso: 'SL', dial: '+232',  label: 'Sierra Leone' },
        { iso: 'SG', dial: '+65',   label: 'Singapore' },
        { iso: 'SX', dial: '+1721', label: 'Sint Maarten' },
        { iso: 'SK', dial: '+421',  label: 'Slovakia' },
        { iso: 'SI', dial: '+386',  label: 'Slovenia' },
        { iso: 'SB', dial: '+677',  label: 'Solomon Islands' },
        { iso: 'SO', dial: '+252',  label: 'Somalia' },
        { iso: 'ZA', dial: '+27',   label: 'South Africa' },
        { iso: 'KR', dial: '+82',   label: 'South Korea' },
        { iso: 'SS', dial: '+211',  label: 'South Sudan' },
        { iso: 'ES', dial: '+34',   label: 'Spain' },
        { iso: 'LK', dial: '+94',   label: 'Sri Lanka' },
        { iso: 'SD', dial: '+249',  label: 'Sudan' },
        { iso: 'SR', dial: '+597',  label: 'Suriname' },
        { iso: 'SE', dial: '+46',   label: 'Sweden' },
        { iso: 'CH', dial: '+41',   label: 'Switzerland' },
        { iso: 'SY', dial: '+963',  label: 'Syria' },
        { iso: 'TW', dial: '+886',  label: 'Taiwan' },
        { iso: 'TJ', dial: '+992',  label: 'Tajikistan' },
        { iso: 'TZ', dial: '+255',  label: 'Tanzania' },
        { iso: 'TH', dial: '+66',   label: 'Thailand' },
        { iso: 'TL', dial: '+670',  label: 'Timor-Leste' },
        { iso: 'TG', dial: '+228',  label: 'Togo' },
        { iso: 'TK', dial: '+690',  label: 'Tokelau' },
        { iso: 'TO', dial: '+676',  label: 'Tonga' },
        { iso: 'TT', dial: '+1868', label: 'Trinidad & Tobago' },
        { iso: 'TN', dial: '+216',  label: 'Tunisia' },
        { iso: 'TR', dial: '+90',   label: 'Turkey' },
        { iso: 'TM', dial: '+993',  label: 'Turkmenistan' },
        { iso: 'TC', dial: '+1649', label: 'Turks & Caicos' },
        { iso: 'TV', dial: '+688',  label: 'Tuvalu' },
        { iso: 'UG', dial: '+256',  label: 'Uganda' },
        { iso: 'UA', dial: '+380',  label: 'Ukraine' },
        { iso: 'AE', dial: '+971',  label: 'United Arab Emirates' },
        { iso: 'GB', dial: '+44',   label: 'United Kingdom' },
        { iso: 'US', dial: '+1',    label: 'United States' },
        { iso: 'UY', dial: '+598',  label: 'Uruguay' },
        { iso: 'UZ', dial: '+998',  label: 'Uzbekistan' },
        { iso: 'VU', dial: '+678',  label: 'Vanuatu' },
        { iso: 'VA', dial: '+379',  label: 'Vatican City' },
        { iso: 'VE', dial: '+58',   label: 'Venezuela' },
        { iso: 'VN', dial: '+84',   label: 'Vietnam' },
        { iso: 'WF', dial: '+681',  label: 'Wallis & Futuna' },
        { iso: 'YE', dial: '+967',  label: 'Yemen' },
        { iso: 'ZM', dial: '+260',  label: 'Zambia' },
        { iso: 'ZW', dial: '+263',  label: 'Zimbabwe' },
    ].sort((a, b) => a.label.localeCompare(b.label));
    return [...pinned, ...rest];
})();

export default function QuickRegisterPage({ referral = null, visa_types = [] }) {
    // Country dial-code + local number are edited separately for a
    // saner UX, then concatenated into a single `phone` string ("+63
    // 917 555 1234") on the way to the server. Default to PH since
    // most of our sign-ups come from there.
    const [dialCode, setDialCode] = useState('+63');
    const [phoneLocal, setPhoneLocal] = useState('');

    const { data, setData, post, processing, errors, wasSuccessful } = useForm({
        // Personal
        first_name: '', last_name: '', email: '', phone: '',
        age: '', gender: '', marital_status: '', country_of_origin: '',
        // Education & interest
        highest_attainment: '', bachelor_course: '', occupation: '',
        pathway_interest: '', pathway_interest_other: '',
        visa_type: '', visa_type_other: '',
        // Partner / spouse (married)
        partner_full_name: '', partner_age: '', partner_education_level: '',
        partner_education_level_other: '', partner_work_experience: '', partner_years_experience: '',
        // Children
        number_of_children: '', children_ages: '', bring_children: '', bring_children_other: '',
        // Additional
        advisor_question: '',
        // Documents
        cv_files: [], passport_files: [], diploma_files: [], transcript_files: [],
        // Consents (map to the two server-required flags)
        terms_accepted: false, declaration_accepted: false,
        // Agent attribution — carried through from /register?ref=CODE. The
        // server validates the code and stamps lead.agent_id on save.
        ref: referral?.code || '',
    });

    const isMarried = data.marital_status === 'Married';
    const showCourseField = data.highest_attainment === "Bachelor's Degree"
        || data.highest_attainment === "Master's Degree";

    // Keep the flat `data.phone` string in sync with the dial-code +
    // local-number pair. Trimmed local wins so a blank number stays blank.
    useEffect(() => {
        const local = phoneLocal.trim();
        setData('phone', local ? `${dialCode} ${local}` : '');
    // setData is Inertia-stable; excluding it keeps the effect tight.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [dialCode, phoneLocal]);

    const submit = (e) => {
        e.preventDefault();
        // Guard against oversized uploads so the visitor gets a clear message
        // instead of a raw "413 Content Too Large" from the server.
        const allFiles = [...(data.cv_files || []), ...(data.passport_files || []), ...(data.diploma_files || []), ...(data.transcript_files || [])];
        const MB = 1024 * 1024;
        const tooBig = allFiles.find((f) => f.size > 10 * MB);
        if (tooBig) {
            alert(`"${tooBig.name}" is larger than 10MB. Please upload a smaller file (max 10MB each).`);
            return;
        }
        const total = allFiles.reduce((sum, f) => sum + (f.size || 0), 0);
        if (total > 200 * MB) {
            alert('Your documents total more than 200MB. Please remove some files or upload smaller versions, then try again.');
            return;
        }
        post('/register', { forceFormData: true });
    };

    const IC = "w-full px-3.5 py-2.5 rounded-lg border border-gray-200 text-sm text-[#282728] bg-white focus:border-[#282728] focus:ring-1 focus:ring-[#282728] outline-none transition-all placeholder:text-gray-400";
    const LBL = "block text-[10.5px] font-bold uppercase tracking-[0.14em] text-gray-500 mb-1.5";
    const err = (k) => errors[k] && <p className="text-[11px] text-red-500 mt-1">{errors[k]}</p>;

    return (
        <div className="min-h-screen bg-[#f6f6f5] font-urbanist text-[#282728] flex flex-col">
            <Head title="Register — EP" />
            <Navbar />

            {/* Hero banner — constrained to the form's width so its margins match */}
            <div className="max-w-3xl w-full mx-auto px-4 sm:px-6 pt-8 md:pt-10">
                <div className="rounded-2xl overflow-hidden border border-gray-100 shadow-sm">
                    <img src={heroBg} alt="Register with EP" className="w-full h-auto block" />
                </div>
            </div>

            {/* Welcome copy — same width / margin as the banner + form */}
            <div className="max-w-3xl w-full mx-auto px-4 sm:px-6 pt-8">
                <span className="text-[11px] font-bold tracking-[0.32em] uppercase text-[#436235]">Registration</span>
                <h1 className="text-3xl md:text-4xl font-semibold tracking-tight mt-2 leading-[1.12] text-[#282728]">Kia ora! Welcome to EP</h1>
                <p className="mt-4 text-gray-600 leading-relaxed">
                    Helping people turn their New Zealand dreams into reality — please take a few minutes to complete this registration form so we can get to know you, accurately assess your application, and keep our records up to date. The details you share allow our advisers to tailor the most suitable study, work, and migration pathways to your background and goals, and to prepare personalised guidance and opportunities ahead of your consultation.
                </p>
            </div>

            {/* Form */}
            <div className="flex-1 max-w-3xl w-full mx-auto px-4 sm:px-6 mt-6 pb-16">
                <div className="bg-white rounded-2xl shadow-xl border border-gray-100 p-6 sm:p-9">
                    {wasSuccessful ? (
                        <div className="py-12 text-center flex flex-col items-center">
                            <div className="w-16 h-16 rounded-full bg-[#282728]/[0.06] flex items-center justify-center mb-5">
                                <CheckCircle size={30} className="text-[#282728]" />
                            </div>
                            <h2 className="text-2xl font-semibold tracking-tight">You're registered</h2>
                            <p className="text-sm text-gray-500 mt-2 max-w-xs leading-relaxed">Thank you — a specialist will review your details and reach out shortly to prepare your proposal.</p>
                            <a href="/" className="mt-7 px-6 py-2.5 rounded-lg bg-[#282728] text-white text-sm font-semibold hover:bg-black transition-colors">Back to home</a>
                        </div>
                    ) : (
                        <form onSubmit={submit} className="space-y-9">

                            {/* Referring-agent banner — only when the URL
                                carried ?ref=CODE that resolves to an
                                actual agent. Purely informational; the
                                real attribution rides in a hidden field
                                below. */}
                            {referral?.agent_name && (
                                <div className="rounded-xl border border-[#436235]/30 bg-[#f4f8f0] px-4 py-3 flex items-start gap-3">
                                    <CheckCircle size={18} className="text-[#436235] flex-shrink-0 mt-0.5" />
                                    <div className="text-[13px] leading-snug">
                                        <div className="font-bold text-[#282728]">Referred by {referral.agent_name}</div>
                                        <div className="text-gray-600 mt-0.5">Your application will be linked to this agent so they can follow up with you.</div>
                                    </div>
                                </div>
                            )}
                            {/* Hidden pass-through for the referral code. */}
                            {data.ref && <input type="hidden" name="ref" value={data.ref} />}

                            {/* Personal Information */}
                            <Section title="Personal Information">
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <Field label="First name *">
                                        <input type="text" required value={data.first_name} onChange={e => setData('first_name', e.target.value)} className={IC} />
                                        {err('first_name')}
                                    </Field>
                                    <Field label="Last name *">
                                        <input type="text" required value={data.last_name} onChange={e => setData('last_name', e.target.value)} className={IC} />
                                        {err('last_name')}
                                    </Field>
                                    <Field label="Email *">
                                        <input
                                            type="email"
                                            required
                                            value={data.email}
                                            onChange={e => setData('email', e.target.value)}
                                            onBlur={e => setData('email', e.target.value.trim())}
                                            autoComplete="email"
                                            className={IC}
                                        />
                                        {err('email')}
                                        {(() => {
                                            const result = inspectEmail(data.email);
                                            if (result.error) {
                                                return <p className="text-[11px] text-amber-600 mt-1">{result.error}</p>;
                                            }
                                            if (result.suggest) {
                                                return (
                                                    <p className="text-[11px] text-gray-600 mt-1">
                                                        Did you mean{' '}
                                                        <button
                                                            type="button"
                                                            onClick={() => setData('email', result.suggest)}
                                                            className="font-semibold text-[#436235] underline underline-offset-2 hover:no-underline"
                                                        >
                                                            {result.suggest}
                                                        </button>
                                                        ?
                                                    </p>
                                                );
                                            }
                                            return null;
                                        })()}
                                    </Field>
                                    <Field label="Phone Number *">
                                        {/* Single visual box: dial-code
                                            chip on the left, phone-number
                                            input filling the rest. The
                                            wrapper owns the border and
                                            focus ring so the two children
                                            read as one control. NB: no
                                            overflow-hidden — the picker's
                                            absolute popup would get
                                            clipped and vanish. */}
                                        <div className="flex items-stretch rounded-lg border border-gray-200 bg-white transition-all focus-within:border-[#282728] focus-within:ring-1 focus-within:ring-[#282728]">
                                            <DialCodePicker
                                                value={dialCode}
                                                onChange={(dial) => setDialCode(dial)}
                                                countries={COUNTRY_CODES}
                                            />
                                            <div className="w-px bg-gray-200 self-stretch" />
                                            <input
                                                type="tel"
                                                required
                                                value={phoneLocal}
                                                onChange={(e) => setPhoneLocal(e.target.value)}
                                                className="flex-1 min-w-0 px-3.5 py-2.5 text-sm text-[#282728] bg-transparent placeholder:text-gray-400 focus:outline-none"
                                                placeholder="Phone number"
                                                inputMode="tel"
                                                autoComplete="tel-national"
                                            />
                                        </div>
                                        {err('phone')}
                                    </Field>
                                    <Field label="Age">
                                        <input type="number" min={0} max={120} value={data.age} onChange={e => setData('age', e.target.value)} className={IC} />
                                    </Field>
                                    <Field label="Gender">
                                        <select value={data.gender} onChange={e => setData('gender', e.target.value)} className={IC}>
                                            <option value="">Select</option>
                                            {GENDERS.map(g => <option key={g}>{g}</option>)}
                                        </select>
                                    </Field>
                                    <Field label="Civil status">
                                        <select value={data.marital_status} onChange={e => setData('marital_status', e.target.value)} className={IC}>
                                            <option value="">Select</option>
                                            {CIVIL_STATUSES.map(s => <option key={s}>{s}</option>)}
                                        </select>
                                    </Field>
                                    <Field label="Country of origin">
                                        {/* Reuses the COUNTRY_CODES list
                                            (same source of truth as the
                                            phone dial-code select), so
                                            the labels stay consistent
                                            with the phone field the
                                            visitor already picked. */}
                                        <select
                                            value={data.country_of_origin}
                                            onChange={e => setData('country_of_origin', e.target.value)}
                                            className={IC}
                                        >
                                            <option value="">Select country</option>
                                            {COUNTRY_CODES.map((c, i) => (
                                                <option key={`origin-${c.iso}-${i}`} value={c.label}>{c.label}</option>
                                            ))}
                                        </select>
                                        {err('country_of_origin')}
                                    </Field>
                                </div>
                            </Section>

                            {/* Education & Interest */}
                            <Section title="Education & Interest">
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <Field label="Current education attainment *">
                                        <select required value={data.highest_attainment} onChange={e => setData('highest_attainment', e.target.value)} className={IC}>
                                            <option value="">Select attainment</option>
                                            {ATTAINMENTS.map(a => <option key={a}>{a}</option>)}
                                        </select>
                                        {err('highest_attainment')}
                                    </Field>
                                    {showCourseField && (
                                        <Field label="What course / program?">
                                            <input type="text" value={data.bachelor_course} onChange={e => setData('bachelor_course', e.target.value)} className={IC} placeholder="e.g. BS Nursing, MBA" />
                                        </Field>
                                    )}
                                    <Field label="Current job / occupation">
                                        <input type="text" value={data.occupation} onChange={e => setData('occupation', e.target.value)} className={IC} />
                                    </Field>
                                    <Field label="What pathway are you interested in?">
                                        <select value={data.pathway_interest} onChange={e => setData('pathway_interest', e.target.value)} className={IC}>
                                            <option value="">Select pathway</option>
                                            {PATHWAYS.map(p => <option key={p}>{p}</option>)}
                                        </select>
                                    </Field>
                                    {data.pathway_interest === 'Other' && (
                                        <Field label="Other pathway">
                                            <input type="text" value={data.pathway_interest_other} onChange={e => setData('pathway_interest_other', e.target.value)} className={IC} />
                                        </Field>
                                    )}
                                    <Field label="Visa applying for">
                                        <select value={data.visa_type} onChange={e => setData('visa_type', e.target.value)} className={IC}>
                                            <option value="">Select a visa</option>
                                            {visa_types.map((v) => <option key={v} value={v}>{v}</option>)}
                                            <option value="Other">Other (type below)</option>
                                        </select>
                                        {err('visa_type')}
                                    </Field>
                                    {data.visa_type === 'Other' && (
                                        <Field label="Other visa">
                                            <input
                                                type="text"
                                                value={data.visa_type_other}
                                                onChange={e => setData('visa_type_other', e.target.value)}
                                                placeholder="Type the visa you're applying for"
                                                className={IC}
                                            />
                                            {err('visa_type_other')}
                                        </Field>
                                    )}
                                </div>
                            </Section>

                            {/* Partner / Spouse — married only */}
                            {isMarried && (
                                <Section title="Partner / Spouse Information">
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <Field label="Full name of partner / spouse">
                                            <input type="text" value={data.partner_full_name} onChange={e => setData('partner_full_name', e.target.value)} className={IC} />
                                        </Field>
                                        <Field label="Age of partner / spouse">
                                            <input type="number" min={0} max={120} value={data.partner_age} onChange={e => setData('partner_age', e.target.value)} className={IC} />
                                        </Field>
                                        <Field label="Partner / spouse current education level">
                                            <select value={data.partner_education_level} onChange={e => setData('partner_education_level', e.target.value)} className={IC}>
                                                <option value="">Select level</option>
                                                {ATTAINMENTS.map(a => <option key={a}>{a}</option>)}
                                            </select>
                                        </Field>
                                        {data.partner_education_level === 'Other' && (
                                            <Field label="Other — partner education level">
                                                <input type="text" value={data.partner_education_level_other} onChange={e => setData('partner_education_level_other', e.target.value)} className={IC} />
                                            </Field>
                                        )}
                                        <Field label="Partner / spouse current work experience">
                                            <input type="text" value={data.partner_work_experience} onChange={e => setData('partner_work_experience', e.target.value)} className={IC} />
                                        </Field>
                                        <Field label="Partner / spouse years of experience">
                                            <input type="text" value={data.partner_years_experience} onChange={e => setData('partner_years_experience', e.target.value)} className={IC} placeholder="e.g. 5 years" />
                                        </Field>
                                    </div>
                                </Section>
                            )}

                            {/* Children — only meaningful for married registrants
                                (or civil statuses that imply a partnership).
                                Single registrants skip the section entirely. */}
                            {isMarried && (
                                <Section title="Children">
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <Field label="Number of children (if any)">
                                            <input type="number" min={0} max={30} value={data.number_of_children} onChange={e => setData('number_of_children', e.target.value)} className={IC} />
                                        </Field>
                                        <Field label="Child age(s)" hint="Separate by commas, e.g. 5, 8, 12">
                                            <input type="text" value={data.children_ages} onChange={e => setData('children_ages', e.target.value)} className={IC} placeholder="5, 8, 12" />
                                        </Field>
                                        <Field label="Will you bring your children?">
                                            <select value={data.bring_children} onChange={e => setData('bring_children', e.target.value)} className={IC}>
                                                <option value="">Select</option>
                                                {BRING_CHILDREN.map(o => <option key={o}>{o}</option>)}
                                            </select>
                                        </Field>
                                        {data.bring_children === 'Other' && (
                                            <Field label="Other — will you bring your children?">
                                                <input type="text" value={data.bring_children_other} onChange={e => setData('bring_children_other', e.target.value)} className={IC} />
                                            </Field>
                                        )}
                                    </div>
                                </Section>
                            )}

                            {/* Additional */}
                            <Section title="Additional Information (Optional)">
                                <Field label="Do you have any specific question for our advisor?" hint="We'll try to address it during the consultation.">
                                    <textarea rows={4} value={data.advisor_question} onChange={e => setData('advisor_question', e.target.value)} className={`${IC} resize-y`} placeholder="Write your questions here…" />
                                </Field>
                            </Section>

                            {/* Documents — all optional. Attaching any of these
                                helps the adviser prepare, but the meeting can
                                still be booked without them. */}
                            <Section title="Documents (Optional)" subtitle="Upload any of these to help us assess your qualifications before the meeting. You can also send them later.">
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <FilePick label="Attach CV" files={data.cv_files} onChange={f => setData('cv_files', f)} />
                                    <FilePick label="Passport" files={data.passport_files} onChange={f => setData('passport_files', f)} />
                                    <FilePick label="Diploma" files={data.diploma_files} onChange={f => setData('diploma_files', f)} />
                                    <FilePick label="Transcript of Record" files={data.transcript_files} onChange={f => setData('transcript_files', f)} />
                                </div>
                                <p className="text-[11px] text-gray-400 mt-2">PDF, DOC/DOCX, XLS/CSV, JPG/JPEG, PNG, GIF — max 10 files each.</p>
                            </Section>

                            {/* Consents */}
                            <div className="space-y-3 pt-2">
                                <label className="flex items-start gap-2.5 cursor-pointer">
                                    <input type="checkbox" required checked={data.terms_accepted} onChange={e => setData('terms_accepted', e.target.checked)} className="mt-0.5 accent-[#282728] w-4 h-4" />
                                    <span className="text-[12px] text-gray-600 leading-relaxed">I consent to receive follow-up communication regarding this consultation, including reminders and related offers from EP.</span>
                                </label>
                                <label className="flex items-start gap-2.5 cursor-pointer">
                                    <input type="checkbox" required checked={data.declaration_accepted} onChange={e => setData('declaration_accepted', e.target.checked)} className="mt-0.5 accent-[#282728] w-4 h-4" />
                                    <span className="text-[12px] text-gray-600 leading-relaxed">I understand that the consultation may be recorded for future viewing purposes.</span>
                                </label>
                            </div>

                            <button
                                type="submit"
                                disabled={processing}
                                className="w-full inline-flex items-center justify-center gap-2 px-6 py-3 rounded-lg bg-[#282728] text-white text-sm font-semibold hover:bg-black transition-colors disabled:opacity-50"
                            >
                                {processing ? 'Submitting…' : <>Submit registration <ArrowRight size={16} /></>}
                            </button>
                        </form>
                    )}
                </div>
            </div>

            <Footer />
        </div>
    );
}

/**
 * Compact country-code picker. Shows "NZ +64 ▼" as the trigger; on
 * click opens a scrollable, searchable panel with the full country
 * catalog. Kept native-select-shaped so `value` is the dial string
 * ("+64") — the parent stays unaware of the ISO. Closes on outside
 * click and Escape.
 */
// ISO codes for the "popular" section pinned at the top of the picker
// — the countries we see most often across sign-ups. Order here is the
// order shown in the popup.
const POPULAR_ISOS = ['NZ', 'AU', 'PH', 'IN', 'CN', 'US'];

function DialCodePicker({ value, onChange, countries }) {
    const [open, setOpen] = useState(false);
    const wrapRef = useRef(null);

    // ISO for the trigger label — first match on dial code (there are
    // dupes: +1 maps to US/CA/many Caribbean states) so the ISO shown
    // reflects the choice they last made.
    const current = countries.find((c) => c.dial === value) || countries[0];

    useEffect(() => {
        if (! open) return;
        const onClick = (e) => { if (wrapRef.current && ! wrapRef.current.contains(e.target)) setOpen(false); };
        const onKey = (e) => { if (e.key === "Escape") setOpen(false); };
        document.addEventListener("mousedown", onClick);
        document.addEventListener("keydown", onKey);
        return () => {
            document.removeEventListener("mousedown", onClick);
            document.removeEventListener("keydown", onKey);
        };
    }, [open]);

    // Popular ISOs pinned at the top, then a divider, then the rest of
    // the alphabetised catalog. No search — the popup relies on
    // browser scroll + typeahead (the native pattern users expect).
    const popular = POPULAR_ISOS.map((iso) => countries.find((c) => c.iso === iso)).filter(Boolean);
    const others = countries.filter((c) => ! POPULAR_ISOS.includes(c.iso));

    const renderRow = (c, i, keyPrefix = "") => {
        const active = c.dial === value && c.iso === current.iso;
        return (
            <button
                key={`${keyPrefix}${c.iso}-${i}`}
                type="button"
                onClick={() => { onChange(c.dial, c); setOpen(false); }}
                className={`w-full flex items-center gap-3 px-3 py-2 text-left text-[13px] transition-colors ${
                    active ? "bg-emerald-50 text-[#282728]" : "hover:bg-gray-50 text-gray-800"
                }`}
            >
                <span className="w-8 font-bold text-gray-700 tracking-wide">{c.iso}</span>
                <span className="flex-1 truncate text-gray-700">{c.label}</span>
                <span className="text-emerald-600 tabular-nums font-medium">{c.dial}</span>
            </button>
        );
    };

    return (
        <div ref={wrapRef} className="relative flex-shrink-0">
            <button
                type="button"
                onClick={() => setOpen((v) => ! v)}
                aria-label="Country code"
                aria-expanded={open}
                className="h-full flex items-center gap-1.5 pl-3 pr-2 text-[13px] font-semibold text-[#282728] hover:text-[#436235] transition-colors focus:outline-none"
            >
                <span className="font-bold">{current.iso}</span>
                <span className="text-gray-500 tabular-nums">{current.dial}</span>
                <ChevronDown
                    size={14}
                    strokeWidth={2.2}
                    className={`text-gray-400 transition-transform duration-150 ${open ? "rotate-180" : ""}`}
                />
            </button>
            {open && (
                <div className="absolute z-30 mt-2 left-0 w-72 bg-white rounded-xl shadow-xl ring-1 ring-black/5 overflow-hidden">
                    <div className="max-h-72 overflow-y-auto py-1">
                        {popular.map((c, i) => renderRow(c, i, "pop-"))}
                        <div className="my-1 border-t border-gray-100" />
                        {others.map((c, i) => renderRow(c, i, "all-"))}
                    </div>
                </div>
            )}
        </div>
    );
}

function Section({ title, subtitle, children }) {
    return (
        <section className="space-y-4">
            <div>
                <h2 className="text-[11px] font-bold uppercase tracking-[0.18em] text-[#282728]">{title}</h2>
                {subtitle && <p className="text-[12px] text-gray-500 mt-1 leading-relaxed">{subtitle}</p>}
                <div className="h-px bg-gray-100 mt-3" />
            </div>
            {children}
        </section>
    );
}

function Field({ label, hint, children }) {
    return (
        <div>
            <label className="block text-[10.5px] font-bold uppercase tracking-[0.14em] text-gray-500 mb-1.5">{label}</label>
            {children}
            {hint && <p className="text-[10.5px] text-gray-400 mt-1">{hint}</p>}
        </div>
    );
}

function FilePick({ label, files = [], onChange }) {
    const id = `reg-${label.replace(/\W+/g, '-').toLowerCase()}`;
    const add = (picked) => onChange([...(files || []), ...Array.from(picked)].slice(0, 10));
    const remove = (idx) => onChange((files || []).filter((_, i) => i !== idx));
    return (
        <div>
            <label className="block text-[10.5px] font-bold uppercase tracking-[0.14em] text-gray-500 mb-1.5">{label}</label>
            <label htmlFor={id} className="flex items-center justify-center gap-2 border border-dashed border-gray-300 rounded-lg px-3 py-2.5 text-center cursor-pointer hover:border-[#282728] hover:bg-gray-50 transition-colors">
                <Upload size={14} className="text-gray-400" />
                <span className="text-[12px] font-semibold text-gray-600">{files?.length ? `${files.length} file(s)` : 'Upload'}</span>
                <input id={id} type="file" multiple accept=".pdf,.doc,.docx,.xls,.csv,.jpg,.jpeg,.png,.gif" className="hidden" onChange={e => { if (e.target.files?.length) add(e.target.files); e.target.value = ''; }} />
            </label>
            {(files || []).length > 0 && (
                <ul className="mt-1.5 space-y-1">
                    {files.map((f, i) => (
                        <li key={i} className="flex items-center gap-1.5 text-[11px] text-gray-600">
                            <FileText size={11} className="text-gray-400 flex-shrink-0" />
                            <span className="flex-1 truncate">{f.name}</span>
                            <button type="button" onClick={() => remove(i)} className="text-gray-400 hover:text-red-600"><X size={11} /></button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
