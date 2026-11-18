import { hasProgramCost } from '@/utils/programCosts.mjs';
import React, { useState } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { Search, ChevronDown, ChevronUp } from 'react-feather';
import { Link } from '@inertiajs/react';
import Navbar from "@/components/layout/Navbar";
import Footer from "@/components/layout/Footer";
import ScrollToTop from "@/components/ui/ScrollToTop";

import heroBg from "@assets/Services/education.png";
import placeholderImg from "@assets/Services/education.png";

const fmt = (val) => {
    if (val === null || val === undefined || val === '') return '—';
    const n = Number(val);
    return Number.isFinite(n)
        ? n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
        : String(val);
};

const FILTER_LABELS = {
    all: 'All Programs',
    diplomas: 'Diplomas',
    bachelors: 'Bachelor',
    masters: 'PG / Masters',
};

export default function FeeGuide({ programs = [] }) {
    const [expandedIds, setExpandedIds] = useState([]);
    const [activeFilter, setActiveFilter] = useState('all');
    const [searchQuery, setSearchQuery] = useState('');

    const toggleAccordion = (id) => {
        setExpandedIds(prev =>
            prev.includes(id) ? prev.filter(i => i !== id) : [...prev, id]
        );
    };

    const filtered = programs
        .filter(p => activeFilter === 'all' || p.category === activeFilter)
        .filter(p => {
            const q = searchQuery.toLowerCase();
            return !q ||
                (p.title || '').toLowerCase().includes(q) ||
                (p.institution || '').toLowerCase().includes(q);
        });

    return (
        <div className="min-h-screen bg-[#f8f9fa] font-urbanist overflow-x-hidden">
            <Navbar />

            {/* Hero Section */}
            <div className="relative h-[40vh] min-h-[350px] w-full overflow-hidden">
                <img
                    src={heroBg}
                    alt="University Campus"
                    className="absolute inset-0 w-full h-full object-cover"
                />
                <div className="absolute inset-0 bg-black/40 flex flex-col items-center justify-center text-center px-4">
                    <h1 className="text-4xl md:text-6xl font-bold text-white mb-4 leading-tight max-w-4xl">
                        good education to build a better future
                    </h1>
                </div>
            </div>

            <div className="container mx-auto px-4 py-16 max-w-4xl">
                <div className="flex justify-center mb-20">
                    <div className="relative w-full max-w-xl">
                        <input
                            type="text"
                            placeholder="Search programs, institutions..."
                            className="w-full px-8 py-4 rounded-full border border-gray-200 bg-white shadow-sm focus:outline-none focus:ring-2 focus:ring-[#436235]/10 focus:border-[#436235] transition-all text-base placeholder-gray-400"
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                        />
                        <Search className="absolute right-6 top-1/2 -translate-y-1/2 text-gray-800 w-5 h-5" strokeWidth={2.5} />
                    </div>
                </div>

                <div className="flex flex-col md:flex-row justify-between items-center mb-12 border-b border-gray-100 pb-2">
                    <div className="text-base font-medium text-[#282728] uppercase tracking-[0.2em] mb-4 md:mb-0">
                        Fee Guide
                    </div>

                    <div className="flex gap-8">
                        {Object.keys(FILTER_LABELS).map((key) => (
                            <button
                                key={key}
                                onClick={() => setActiveFilter(key)}
                                className={`text-[10px] font-bold uppercase tracking-[0.15em] pb-3 transition-all border-b-2 ${activeFilter === key
                                    ? 'text-[#436235] border-[#436235]'
                                    : 'text-gray-500 border-transparent hover:text-gray-600'
                                    }`}
                            >
                                {FILTER_LABELS[key]}
                            </button>
                        ))}
                    </div>
                </div>

                <div className="space-y-4">
                    {filtered.length === 0 ? (
                        <div className="text-center py-16 text-gray-500">
                            <p className="text-sm">No programs found matching your filters.</p>
                        </div>
                    ) : filtered.map((program) => {
                        const isExpanded = expandedIds.includes(program.id);
                        return (
                            <div
                                key={program.id}
                                className="bg-white rounded-xl overflow-hidden shadow-[0_2px_15px_-3px_rgba(0,0,0,0.07),0_10px_20px_-2px_rgba(0,0,0,0.04)] border border-gray-100 transition-all duration-300"
                            >
                                <div
                                    className="p-4 flex items-center cursor-pointer gap-4"
                                    onClick={() => toggleAccordion(program.id)}
                                >
                                    <div className="w-16 h-12 rounded-lg overflow-hidden flex-shrink-0 bg-gray-100">
                                        <img
                                            src={program.image_url || placeholderImg}
                                            className="w-full h-full object-cover"
                                            alt={program.title}
                                        />
                                    </div>
                                    <div className="flex-grow min-w-0">
                                        <div className="flex items-center gap-2 mb-0.5 flex-wrap">
                                            <h3 className="text-base font-bold text-[#282728] truncate">{program.title}</h3>
                                            <span className="bg-[#436235] text-white text-[8px] font-bold px-1.5 py-0.5 rounded leading-none flex-shrink-0">
                                                Level {program.level}
                                            </span>
                                        </div>
                                        <p className="text-[10px] text-gray-500 font-medium">{program.institution || '—'}</p>
                                    </div>
                                    <div className="text-right flex items-center gap-4 flex-shrink-0">
                                        <div className="hidden sm:block">
                                            <p className="text-[10px] text-gray-600 font-medium leading-none mb-1">Start from</p>
                                            <p className="text-sm font-bold text-gray-800 tabular-nums">
                                                {hasProgramCost(program.tuition_fee) ? fmt(program.tuition_fee) : 'TBA'}
                                                {hasProgramCost(program.tuition_fee) && program.tuition_fee_notes && (
                                                    <span className="ml-1 text-[10px] font-normal text-gray-600">
                                                        ({program.tuition_fee_notes})
                                                    </span>
                                                )}
                                            </p>
                                        </div>
                                        <div className="text-gray-500">
                                            {isExpanded ? <ChevronUp className="w-5 h-5" /> : <ChevronDown className="w-5 h-5" />}
                                        </div>
                                    </div>
                                </div>

                                <AnimatePresence>
                                    {isExpanded && (
                                        <motion.div
                                            initial={{ height: 0, opacity: 0 }}
                                            animate={{ height: "auto", opacity: 1 }}
                                            exit={{ height: 0, opacity: 0 }}
                                            transition={{ duration: 0.3, ease: "easeInOut" }}
                                            style={{ overflow: 'hidden' }}
                                        >
                                            <div className="px-6 pb-8 pt-4 border-t border-gray-50">
                                                <div className="grid grid-cols-1 lg:grid-cols-12 gap-12 mt-4">
                                                    {/* Tuition Fee */}
                                                    <div className="lg:col-span-5 bg-gray-50/50 p-6 rounded-2xl border border-gray-100">
                                                        <div className="flex justify-between items-center mb-6 pb-2 border-b border-gray-200">
                                                            <span className="text-[10px] font-bold text-gray-600 uppercase tracking-widest">Tuition Fee</span>
                                                            <span className="text-[10px] font-bold text-gray-600 uppercase tracking-widest">Amount (NZD)</span>
                                                        </div>

                                                        <div className="space-y-3">
                                                            {hasProgramCost(program.tuition_fee) && (<div className="flex justify-between items-baseline gap-3">
                                                                <span className="text-gray-600 text-sm flex-shrink-0">Tuition</span>
                                                                <span className="font-bold text-[#282728] text-right">
                                                                    <span className="text-xl tabular-nums">
                                                                        {hasProgramCost(program.tuition_fee) ? fmt(program.tuition_fee) : '—'}
                                                                    </span>
                                                                    {hasProgramCost(program.tuition_fee) && program.tuition_fee_notes && (
                                                                        <span className="text-xs font-normal text-gray-600 ml-2">
                                                                            ({program.tuition_fee_notes})
                                                                        </span>
                                                                    )}
                                                                </span>
                                                            </div>)}
                                                            {!hasProgramCost(program.tuition_fee) && (
                                                                <p className="text-xs text-gray-500">No tuition fee specified.</p>
                                                            )}
                                                        </div>
                                                    </div>

                                                    {/* Additional Costs */}
                                                    <div className="lg:col-span-7 grid grid-cols-2 gap-y-10 gap-x-8 py-4">
                                                        {hasProgramCost(program.insurance_fee) && (<div>
                                                            <div className="flex items-baseline gap-2 mb-1">
                                                                <span className="text-[10px] text-gray-500 font-medium tracking-wider">NZD</span>
                                                                <div className="text-xl font-bold text-[#282728] tabular-nums">{fmt(program.insurance_fee)}</div>
                                                            </div>
                                                            <p className="text-[10px] text-gray-500 uppercase tracking-[0.15em] font-medium">Insurance (indicative)</p>
                                                        </div>)}
                                                        {hasProgramCost(program.visa_processing_fee) && (<div>
                                                            <div className="flex items-baseline gap-2 mb-1">
                                                                <span className="text-[10px] text-gray-500 font-medium tracking-wider">NZD</span>
                                                                <div className="text-xl font-bold text-[#282728] tabular-nums">{fmt(program.visa_processing_fee)}</div>
                                                            </div>
                                                            <p className="text-[10px] text-gray-500 uppercase tracking-[0.15em] font-medium">Visa Processing Fee</p>
                                                        </div>)}
                                                        {hasProgramCost(program.living_expense) && (<div>
                                                            <div className="flex items-baseline gap-2 mb-1">
                                                                <span className="text-[10px] text-gray-500 font-medium tracking-wider">NZD</span>
                                                                <div className="text-xl font-bold text-[#282728] tabular-nums">{fmt(program.living_expense)}</div>
                                                            </div>
                                                            <p className="text-[10px] text-gray-500 uppercase tracking-[0.15em] font-medium">Living Expense (one year)</p>
                                                        </div>)}
                                                        {hasProgramCost(program.accommodation) && (<div>
                                                            <div className="text-xl font-bold text-[#282728] mb-1">{program.accommodation || '—'}</div>
                                                            <p className="text-[10px] text-gray-500 uppercase tracking-[0.15em] font-medium">Accommodation (single occupancy)</p>
                                                        </div>)}
                                                    </div>
                                                </div>

                                                <div className="flex justify-end gap-3 mt-10">
                                                    <Link
                                                        href={`/program-details/${program.slug}`}
                                                        className="px-5 py-2 border border-gray-200 text-[10px] font-bold rounded-lg text-gray-500 hover:text-gray-600 hover:bg-gray-50 transition-all uppercase tracking-widest"
                                                    >
                                                        Details
                                                    </Link>
                                                    <Link
                                                        href="/booking"
                                                        className="px-6 py-2 bg-[#436235] text-white text-[10px] font-bold rounded-lg hover:bg-[#385029] transition-all shadow-md active:scale-95 uppercase tracking-widest"
                                                    >
                                                        Book Now
                                                    </Link>
                                                </div>
                                            </div>
                                        </motion.div>
                                    )}
                                </AnimatePresence>
                            </div>
                        );
                    })}
                </div>
            </div>

            <ScrollToTop />
            <Footer />
        </div>
    );
}
