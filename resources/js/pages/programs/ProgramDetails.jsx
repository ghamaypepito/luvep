import { hasProgramCost } from '@/utils/programCosts.mjs';
import React from 'react';
import { motion } from 'framer-motion';
import { Check, ChevronRight, ArrowLeft } from 'react-feather';
import { Link } from '@inertiajs/react';
import Navbar from "@/components/layout/Navbar";
import Footer from "@/components/layout/Footer";
import ScrollToTop from "@/components/ui/ScrollToTop";
import QuickLeadForm from "@/components/ui/QuickLeadForm";
// Shared with the tracker's program modal so both surfaces normalise the
// legacy "sections" shapes the same way.
import { renderSections } from "@/utils/programSections";

// Assets (Using placeholders if exact ones not available, standardizing)
import heroBg from "@assets/Services/education.png";
import programImg from "@assets/Services/pathways.png"; // Changed from Testimonies/testi1.png to a more relevant education asset


export default function ProgramDetails({ program }) {
    const paragraphs = (program?.description || '').split(/\n\n+/).filter(Boolean);
    const fees = Array.isArray(program?.fee_guide) ? program.fee_guide : [];

    // Multiple tuition rows: [{ label, amount, notes }]. Fall back to the
    // legacy single tuition_fee / tuition_fee_notes when none are saved.
    const tuitionRows = (Array.isArray(program?.tuition_fees) && program.tuition_fees.length > 0
        ? program.tuition_fees
        : (program?.tuition_fee
            ? [{ label: '', amount: program.tuition_fee, notes: program.tuition_fee_notes }]
            : [])
    ).filter(row => hasProgramCost(row.amount));
    const fmt = (val) => {
        if (val === null || val === undefined || val === '') return '—';
        const n = Number(val);
        return Number.isFinite(n) ? n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : String(val);
    };

    return (
        <div className="min-h-screen bg-white font-urbanist overflow-x-hidden">
            <Navbar />

            {/* Hero Section */}
            <div className="relative h-[50vh] min-h-[400px] w-full overflow-hidden">
                <img
                    src={heroBg}
                    alt="Medical Professionals"
                    className="absolute inset-0 w-full h-full object-cover"
                />
                <div className="absolute inset-0 bg-black/40 flex flex-col items-center justify-center text-center">
                    <h1 className="text-6xl md:text-8xl font-bold text-white mb-2 tracking-tight">EP</h1>
                    <p className="text-sm md:text-base text-white/90 font-light tracking-wide">New Zealand Journey</p>
                </div>
            </div>

            {/* Main Content Container */}
            <div className="container mx-auto px-4 py-16 max-w-7xl">

                {/* Breadcrumb / Back Navigation */}
                <div className="mb-8 flex items-center justify-between gap-4 flex-wrap">
                    <nav aria-label="Breadcrumb" className="flex items-center gap-3 text-xl md:text-2xl font-bold uppercase tracking-wider">
                        <Link href="/programs-levels" className="text-gray-500 hover:text-[#436235] transition-colors">
                            Programs
                        </Link>
                        <ChevronRight size={22} className="text-gray-300" />
                        <span className="text-[#436235] truncate max-w-[260px] sm:max-w-md md:max-w-xl">
                            {program?.title || 'Program'}
                        </span>
                    </nav>
                    <Link
                        href="/programs-levels"
                        className="inline-flex items-center gap-2 px-4 py-2 border border-gray-200 rounded-full text-[10px] font-bold uppercase tracking-widest text-gray-600 hover:text-white hover:bg-[#436235] hover:border-[#436235] transition-all"
                    >
                        <ArrowLeft size={14} /> Back to Programs
                    </Link>
                </div>

                {/* About This Program Section - Card Style */}
                <div className="mb-16">
                    <h4 className="text-xs font-bold text-gray-500 uppercase tracking-widest mb-6">About This Program</h4>

                    <div className="bg-white shadow-[0_8px_30px_rgb(0,0,0,0.04)] rounded-3xl overflow-hidden border border-gray-100">
                        <div className="grid grid-cols-1 lg:grid-cols-2">
                            {/* Left: Text Content */}
                            <div className="p-4 lg:p-3 flex flex-col justify-center">
                                <h1 className="text-4xl md:text-5xl font-bold text-[#282728] mb-2 leading-tight">
                                    {program?.title || 'Program'}
                                </h1>
                                <h2 className="text-2xl font-normal text-[#436235] mb-8">{program?.location || ''}</h2>

                                <div className="prose prose-lg text-gray-600 leading-relaxed space-y-4">
                                    {paragraphs.length === 0 ? (
                                        <p>No description available.</p>
                                    ) : paragraphs.map((p, i) => <p key={i}>{p}</p>)}
                                </div>
                            </div>

                            {/* Right: Image */}
                            <div className="p-4 lg:p-3 h-full min-h-[400px]">
                                <div className="relative h-full w-full rounded-[5px] overflow-hidden shadow-lg">
                                    <img
                                        src={program?.image_url || programImg}
                                        alt={program?.title || ''}
                                        className="absolute inset-0 w-full h-full object-cover"
                                    />
                                    {program?.intake_months && (
                                        <div className="absolute top-6 right-6 bg-[#1a1a1a]/80 backdrop-blur-sm text-white px-4 py-2 rounded-lg text-sm font-medium uppercase tracking-wide">
                                            {program.intake_months}
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>

                        {/* Stats Bar — 2-col on mobile so text isn't crammed,
                            5-col on desktop. */}
                        <div className="bg-[#1a1a1a] text-white py-10 sm:py-14 px-4 sm:px-6">
                            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-y-8 items-center">
                                <div className="flex flex-col items-center sm:border-r border-white/10 last:border-r-0 px-2">
                                    <span className="text-4xl sm:text-5xl lg:text-6xl font-bold mb-2 tabular-nums">{program?.level ?? '—'}</span>
                                    <span className="text-sm sm:text-base text-gray-300 font-semibold uppercase tracking-[0.15em]">Level</span>
                                </div>
                                <div className="flex flex-col items-center sm:border-r border-white/10 last:border-r-0 px-2">
                                    <span className="text-4xl sm:text-5xl lg:text-6xl font-bold mb-2 tabular-nums">{program?.duration_months ?? '—'}</span>
                                    <div className="text-sm sm:text-base text-gray-300 font-semibold uppercase tracking-[0.15em] text-center leading-relaxed">
                                        Months<br />(Duration)
                                    </div>
                                </div>
                                <div className="flex flex-col items-center sm:border-r border-white/10 last:border-r-0 px-2">
                                    <span className="text-4xl sm:text-5xl lg:text-6xl font-bold mb-2 tabular-nums">{program?.credits ?? '—'}</span>
                                    <span className="text-sm sm:text-base text-gray-300 font-semibold uppercase tracking-[0.15em]">Credits</span>
                                </div>
                                <div className="flex flex-col items-center sm:border-r border-white/10 last:border-r-0 px-2">
                                    <span className="text-4xl sm:text-5xl lg:text-6xl font-bold mb-2 tabular-nums">{program?.residency_points ?? '—'}</span>
                                    <div className="text-sm sm:text-base text-gray-300 font-semibold uppercase tracking-[0.15em] text-center leading-relaxed">
                                        Points of Residency
                                    </div>
                                </div>
                                <div className="flex flex-col items-center sm:border-r border-white/10 last:border-r-0 px-2 col-span-2 sm:col-span-1">
                                    {/* Numbers show at the big stat size; a word like "Unlimited" steps down so it fits the cell. */}
                                    {/^\d{1,3}$/.test(String(program?.hours_per_week ?? ''))
                                        ? <span className="text-4xl sm:text-5xl lg:text-6xl font-bold mb-2 tabular-nums">{program?.hours_per_week ?? '—'}</span>
                                        : <span className="text-2xl sm:text-3xl lg:text-4xl font-bold mb-2 text-center break-words max-w-full">{program?.hours_per_week ?? '—'}</span>}
                                    <div className="text-sm sm:text-base text-gray-300 font-semibold uppercase tracking-[0.15em] text-center leading-relaxed">
                                        Hours per Week<br />(Works Right)
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Info Cards Grid */}
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8 mb-16">
                    {/* Entry Requirements Card */}
                    <div className="bg-white p-8 rounded-2xl shadow-[0_4px_20px_rgba(0,0,0,0.05)] border border-gray-100 h-full">
                        <h3 className="text-lg font-bold text-[#282728] mb-4">Entry Requirements</h3>
                        <div className="text-sm text-gray-600 leading-relaxed">
                            {renderSections(program?.entry_requirements, 'No entry requirements specified.')}
                        </div>
                    </div>

                    {/* English Requirements Card */}
                    <div className="bg-white p-8 rounded-2xl shadow-[0_4px_20px_rgba(0,0,0,0.05)] border border-gray-100 h-full">
                        <h3 className="text-lg font-bold text-[#282728] mb-4">English Requirements</h3>
                        <p className="text-sm text-gray-600 leading-relaxed whitespace-pre-line">
                            {program?.english_requirements || 'No English requirements specified.'}
                        </p>
                    </div>

                    {/* Employment Outcomes Card */}
                    <div className="bg-white p-8 rounded-2xl shadow-[0_4px_20px_rgba(0,0,0,0.05)] border border-gray-100 h-full">
                        <h3 className="text-lg font-bold text-[#282728] mb-4">Employment Outcomes</h3>
                        <div className="text-sm text-gray-600 leading-relaxed">
                            {renderSections(program?.employment_outcomes, 'No employment outcomes specified.')}
                        </div>
                    </div>

                    {/* Post Study Card */}
                    <div className="bg-white p-8 rounded-2xl shadow-[0_4px_20px_rgba(0,0,0,0.05)] border border-gray-100 h-full">
                        <h3 className="text-lg font-bold text-[#282728] mb-4">Post Study</h3>
                        <p className="text-sm text-gray-600 leading-relaxed">
                            {program?.post_study || 'No post-study information specified.'}
                        </p>
                    </div>

                    {/* Specialization Card — hidden when empty */}
                    {program?.specialization && String(program.specialization).trim() && (
                        <div className="bg-white p-8 rounded-2xl shadow-[0_4px_20px_rgba(0,0,0,0.05)] border border-gray-100 h-full">
                            <h3 className="text-lg font-bold text-[#282728] mb-4">Specialization</h3>
                            <p className="text-sm text-gray-600 leading-relaxed">
                                {program.specialization}
                            </p>
                        </div>
                    )}

                    {/* Other Benefits Card — hidden when empty */}
                    {Array.isArray(program?.other_benefits) && program.other_benefits.filter(b => b && String(b).trim()).length > 0 && (
                        <div className="bg-white p-8 rounded-2xl shadow-[0_4px_20px_rgba(0,0,0,0.05)] border border-gray-100 h-full">
                            <h3 className="text-lg font-bold text-[#282728] mb-4">Other Benefits</h3>
                            <ul className="list-disc pl-5 space-y-1.5 text-sm text-gray-600 leading-relaxed marker:text-[#436235]">
                                {program.other_benefits.filter(b => b && String(b).trim()).map((b, i) => (
                                    <li key={i}>{b}</li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>

                {/* Detailed Other Benefits Alert */}
                <div className="mb-16">
                    <h3 className="text-lg font-bold text-[#282728] mb-6">Other Benefits</h3>
                    <div className="bg-white border border-gray-200 rounded-xl p-6 flex items-start gap-4 shadow-sm w-full md:w-1/2">
                        <div className="min-w-[24px] h-6 bg-[#436235] rounded flex items-center justify-center mt-0.5">
                            <Check className="w-4 h-4 text-white" strokeWidth={3} />
                        </div>
                        <div className="text-sm text-gray-600 leading-relaxed flex-1">
                            {renderSections(program?.other_benefits, 'No other benefits specified.')}
                        </div>
                    </div>
                </div>

                {/* Fee Guide */}
                <div>
                    <h3 className="text-xs font-bold text-gray-500 uppercase tracking-widest mb-6">Fee Guide</h3>
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-12 items-start">
                        {/* Tuition Fee */}
                        <div>
                            <div className="flex justify-between items-center mb-6 pb-2 border-b border-gray-200">
                                <span className="text-sm font-bold text-[#282728] uppercase tracking-wider">Tuition Fee</span>
                                <span className="text-sm font-bold text-[#282728] uppercase tracking-wider">Amount (NZD)</span>
                            </div>

                            <div className="space-y-3">
                                {tuitionRows.length > 0 ? (
                                    tuitionRows.map((row, i) => (
                                        <div key={i} className="flex justify-between items-baseline gap-4">
                                            <span className="text-gray-600 text-sm flex-shrink-0">{row.label || 'Tuition'}</span>
                                            <span className="font-bold text-[#282728] text-right">
                                                <span className="text-2xl tabular-nums">
                                                    {row.amount !== '' && row.amount !== null && row.amount !== undefined ? fmt(row.amount) : '—'}
                                                </span>
                                                {row.notes && (
                                                    <span className="text-sm font-normal text-gray-600 ml-2">
                                                        ({row.notes})
                                                    </span>
                                                )}
                                            </span>
                                        </div>
                                    ))
                                ) : (
                                    <p className="text-sm text-gray-500">No tuition fee specified.</p>
                                )}
                            </div>

                            {/*
                            Legacy regional fee guide — kept for backward compatibility with seeded data.
                            Uncomment the block below to display per-region fees if any are saved on the program.
                            */}
                            {/*
                            <div className="mt-8 pt-6 border-t border-gray-200">
                                <div className="flex justify-between items-center mb-4 pb-2 border-b border-gray-100">
                                    <span className="text-xs font-bold text-gray-600 uppercase tracking-wider">Schools</span>
                                    <span className="text-xs font-bold text-gray-600 uppercase tracking-wider">Fees</span>
                                </div>
                                <div className="space-y-4">
                                    {fees.length === 0 ? (
                                        <p className="text-sm text-gray-500">No fee data available.</p>
                                    ) : fees.map((row, i) => (
                                        <div key={i} className="flex justify-between items-center text-sm">
                                            <span className="text-gray-600">{row.region}</span>
                                            <span className="font-bold text-[#282728]">{fmt(row.fee)}</span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                            */}
                        </div>

                        {/* Cost Card */}
                        {[program?.insurance_fee, program?.visa_processing_fee, program?.living_expense, program?.accommodation].some(hasProgramCost) && (<div className="bg-[#282728] text-white rounded-2xl p-8 lg:p-10 shadow-2xl">
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-y-10 gap-x-8">
                                {hasProgramCost(program?.insurance_fee) && (<div>
                                    <div className="flex items-baseline gap-2 mb-1">
                                        <span className="text-[10px] text-gray-500 font-medium tracking-wider">NZD</span>
                                        <h4 className="text-2xl font-bold tabular-nums">{fmt(program?.insurance_fee)}</h4>
                                    </div>
                                    <p className="text-[10px] text-gray-500 uppercase tracking-wider">Insurance (indicative)</p>
                                </div>)}
                                {hasProgramCost(program?.visa_processing_fee) && (<div>
                                    <div className="flex items-baseline gap-2 mb-1">
                                        <span className="text-[10px] text-gray-500 font-medium tracking-wider">NZD</span>
                                        <h4 className="text-2xl font-bold tabular-nums">{fmt(program?.visa_processing_fee)}</h4>
                                    </div>
                                    <p className="text-[10px] text-gray-500 uppercase tracking-wider">Visa Processing Fee</p>
                                </div>)}
                                {hasProgramCost(program?.living_expense) && (<div>
                                    <div className="flex items-baseline gap-2 mb-1">
                                        <span className="text-[10px] text-gray-500 font-medium tracking-wider">NZD</span>
                                        <h4 className="text-2xl font-bold tabular-nums">{fmt(program?.living_expense)}</h4>
                                    </div>
                                    <p className="text-[10px] text-gray-500 uppercase tracking-wider">Living Expense (one year)</p>
                                </div>)}
                                {hasProgramCost(program?.accommodation) && (<div>
                                    <h4 className="text-xl font-bold mb-1">{program?.accommodation || '—'}</h4>
                                    <p className="text-[10px] text-gray-500 uppercase tracking-wider">Accommodation (single occupancy)</p>
                                </div>)}
                            </div>
                        </div>)}
                    </div>
                </div>

            </div>

            {/* Soft capture — programme detail pages were dead-ends with no
                next-step CTA. Sales gets a programme-tagged lead. */}
            <section className="bg-white py-14 sm:py-20 border-t border-gray-100">
                <div className="max-w-3xl mx-auto px-4">
                    <div className="text-center mb-7">
                        <p className="text-[11px] font-semibold uppercase tracking-[0.22em] text-[#436235] mb-2">
                            Interested in this programme?
                        </p>
                        <h3 className="text-2xl md:text-3xl font-bold text-[#282728]">
                            Talk to a human about {program?.name || 'this programme'}
                        </h3>
                        <p className="text-sm text-gray-500 mt-2 max-w-xl mx-auto">
                            Get personalised intake dates, fee breakdowns, and visa-pathway advice — straight from our education team.
                        </p>
                    </div>
                    <QuickLeadForm
                        source={`program-details:${program?.slug || program?.id || 'unknown'}`}
                        defaultInterest="Education"
                        variant="card"
                        headline="Your details"
                        subtext="We'll reply within 24 hours. No obligation."
                    />
                </div>
            </section>

            <ScrollToTop />
            <Footer />
        </div>
    );
}
