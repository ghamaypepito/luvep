import React, { useState } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { Check, ArrowRight, Calendar, MessageCircle, Mail, Facebook, Linkedin } from 'react-feather';
import { Link } from "@inertiajs/react";
import Navbar from "@/components/layout/Navbar";
import Footer from "@/components/layout/Footer";
import ScrollToTop from "@/components/ui/ScrollToTop";
import LearnAbout from "./LearnAbout";
import ThreePillars from "./ThreePillars";
import { team as teamMembers } from "@/data/team";

// Import assets
import GroupPic from "@assets/about_us/group_pic.png";
import GroupPic2 from "@assets/about_us/group_pic2.png";
import DevLIA from "@assets/team/Dev.png";
import LogoBackdrop from "@assets/newlogosite.png";
import LogoBlack from "@assets/newlogosite.png";

// Team Members Data (images still needed for the world-map locations panels)
import DevImg from "@assets/team/Dev.png";
import DinaImg from "@assets/team/dina.png";
import EmmaImg from "@assets/team/emma.png";
import DaiImg from "@assets/team/dai.png";
import EmilyImg from "@assets/team/emily.png";
import BryllImg from "@assets/team/bryll.png";

// Quick-link icon button styling for the team grid (sits on the dark photo).
const QUICK_ICON_CLASS =
    "pointer-events-auto flex h-8 w-8 items-center justify-center rounded-full bg-white/15 text-white backdrop-blur-sm transition-all duration-200 hover:scale-110 hover:bg-[#436235]";
// Strip non-digits for the wa.me link.
const waNumber = (raw) => (raw || "").replace(/\D/g, "");

export default function AboutUs() {
    const [activeLocation, setActiveLocation] = useState(null);

    return (
        <div className="min-h-screen bg-white font-urbanist overflow-x-hidden">
            <Navbar />

            {/* Hero Section */}
            <section className="relative h-[65vh] min-h-[500px] w-full bg-[#f3f4f6] flex flex-col items-center justify-center pt-20">
                <div className="absolute inset-0 flex items-center justify-center opacity-10 pointer-events-none p-20">
                    <img
                        src={LogoBackdrop}
                        alt="EP Logo Backdrop"
                        className="w-full max-w-4xl object-contain opacity-50 grayscale"
                    />
                </div>

                <div className="relative z-10 text-center px-4">
                    <h1 className="text-5xl md:text-7xl font-medium text-[#282728] mb-6">
                        About <span className="font-bold">Us</span>
                    </h1>
                    <p className="text-xl md:text-2xl text-white font-medium tracking-widest uppercase opacity-80">
                        emerge. energise. evolve
                    </p>
                </div>
            </section>

            {/* Content Section: Emerge, Energise, Evolve */}
            <section className="py-32 container mx-auto px-4 max-w-7xl">
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-20 items-center">
                    {/* Left: Overlapping Images and Stats Box */}
                    <div className="relative">
                        {/* Background Image (group_pic2) */}
                        <div className="bg-gray-100 rounded-lg aspect-[5/4] w-[85%] overflow-hidden shadow-xl">
                            <img
                                src={GroupPic2}
                                alt="EP Celebration"
                                className="w-full h-full object-cover"
                            />
                        </div>

                        {/* Foreground Image (group_pic) */}
                        <div className="absolute top-40 right-0 w-[60%] aspect-square rounded-lg overflow-hidden shadow-2xl">
                            <img
                                src={GroupPic}
                                alt="EP Professional Team"
                                className="w-full h-full object-cover"
                            />
                        </div>

                        {/* Overlapping dark box with green bottom border */}
                        <div className="absolute -bottom-10 -left-10 bg-[#282728] py-5 px-3 text-white min-w-[170px] shadow-2xl border-b-[6px] border-[#436235] text-center z-20">
                            <div className="text-3xl font-bold mb-0.5">300 +</div>
                            <div className="text-[10px] text-gray-600 uppercase tracking-[0.2em]">
                                Visa Approvals
                            </div>
                        </div>
                    </div>

                    {/* Right: Content */}
                    <div className="lg:pl-12 mt-20 lg:mt-0">
                        <div className="flex items-center gap-4 mb-8">
                            <span className="text-[10px] font-bold text-gray-500 uppercase tracking-widest">About Us</span>
                            <div className="h-[2px] w-12 bg-[#436235]"></div>
                        </div>

                        <h2 className="text-5xl md:text-6xl font-bold text-[#282728] mb-10 leading-tight">
                            emerge. energise. evolve
                        </h2>

                        <p className="text-lg text-gray-600 mb-12 leading-relaxed font-light">
                            Pathways empowers students to emerge. energise. and evolve by guiding them through global education opportunities, scholarships, and career pathways.
                        </p>

                        <ul className="space-y-8 mb-16">
                            {[
                                "A licensed Education and Migration Consultancy based in Auckland, New Zealand.",
                                "Providing families end-to-end support from course selection to residency.",
                                "Operating in New Zealand and Australia.",
                                "Accredited partners with top NZ institutions."
                            ].map((item, index) => (
                                <li key={index} className="flex items-start gap-5 group">
                                    <div className="mt-1 w-6 h-6 bg-[#436235] rounded-full flex items-center justify-center flex-shrink-0 transition-transform group-hover:scale-110">
                                        <Check className="text-white w-3.5 h-3.5" strokeWidth={4} />
                                    </div>
                                    <span className="text-sm text-gray-500 font-medium italic leading-relaxed">{item}</span>
                                </li>
                            ))}
                        </ul>

                        <div className="flex flex-wrap gap-5">
                            <a href="/programs-levels" className="px-10 py-4 bg-[#282728] text-white text-[12px] font-bold rounded flex items-center gap-4 hover:bg-black transition-all uppercase tracking-widest shadow-xl active:scale-95 group">
                                Programs <ArrowRight size={16} className="transition-transform group-hover:translate-x-1" />
                            </a>
                            <a href="/booking" className="px-10 py-4 bg-white border border-gray-300 text-[#282728] text-[12px] font-bold rounded hover:bg-gray-50 transition-all uppercase tracking-widest shadow-sm">
                                Contact
                            </a>
                        </div>
                    </div>
                </div>
            </section>

            {/* How ePathways Helps You Section */}
            <ThreePillars />

            {/* Licensed and Accredited Section */}
            <section className="py-32 bg-white">
                <div className="container mx-auto px-4 max-w-7xl">
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-24 items-start">
                        {/* Left: Benefits */}
                        <div className="pt-12">
                            <div className="flex items-center gap-4 mb-8">
                                <span className="text-[10px] font-bold text-gray-500 uppercase tracking-widest">Licensed and Accredited in New Zealand</span>
                                <div className="h-[2px] w-12 bg-[#436235]"></div>
                            </div>

                            <h2 className="text-5xl font-bold text-[#282728] mb-16 leading-tight uppercase tracking-tight">
                                Licensed and Accredited <br /> in <span className="text-[#436235]">New Zealand</span>
                            </h2>

                            <div className="space-y-5 max-w-lg">
                                {[
                                    "Partnered with top NZ Schools and Employers",
                                    "Collaborates with Allianz & Southern Cross for insurance",
                                    "Compliant with NZQA and INZ standards"
                                ].map((item, index) => (
                                    <div key={index} className="bg-[#282728] px-8 py-6 rounded-xl text-gray-300 text-sm font-medium tracking-wide shadow-lg border-l-4 border-transparent hover:border-[#436235] transition-all duration-300">
                                        {item}
                                    </div>
                                ))}
                            </div>
                        </div>

                        {/* Right: Advisor Card */}
                        <div className="bg-gray-50 rounded-3xl shadow-inner border border-gray-100 overflow-hidden">
                            <div className="flex flex-col h-full bg-white relative group">
                                <div className="p-10 lg:p-12 pb-0 relative z-10 bg-white">
                                    <div className="flex justify-between items-start mb-10">
                                        <h3 className="text-3xl lg:text-4xl font-bold text-[#282728] uppercase tracking-tight leading-[1.2]">
                                            Licensed Immigration<br /><span className="text-gray-500">Advisers (LIA)</span>
                                        </h3>
                                        <div className="w-16 h-16 shrink-0 bg-[#436235]/10 rounded-full flex items-center justify-center p-2 border border-[#436235]/20 backdrop-blur-sm ml-4">
                                            <div className="w-full h-full bg-[#436235] rounded-full flex items-center justify-center text-white text-[8px] uppercase font-bold text-center leading-none">
                                                Licensed<br />IAA
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div className="relative aspect-[3/4] overflow-hidden">
                                    <img
                                        src={DevLIA}
                                        alt="Dev Bhageerutty (LIA)"
                                        className="w-full h-full object-cover transition-transform duration-1000 group-hover:scale-105"
                                    />
                                    <div className="absolute inset-0 bg-gradient-to-t from-[#282728] via-transparent to-transparent opacity-90"></div>
                                    <div className="absolute bottom-10 left-10 text-white z-20">
                                        <div className="text-3xl font-bold mb-2">Dev Bhageerutty</div>
                                        <div className="text-[10px] text-gray-300 uppercase tracking-[0.2em] font-medium leading-relaxed mb-6">
                                            (LIA 202401351)<br />
                                            dev@luvep.com
                                        </div>
                                        <div className="flex gap-2">
                                            <div className="w-2.5 h-2.5 rounded-full bg-white shadow-glow"></div>
                                            <div className="w-2.5 h-2.5 rounded-full bg-white/30"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            {/* The Team Behind ePathways Section */}
            <section className="py-24 bg-white border-t border-gray-100">
                <div className="container mx-auto px-4 max-w-7xl">
                    <div className="flex flex-col items-center mb-20 text-center">
                        <span className="text-[10px] font-bold text-gray-500 uppercase tracking-[0.3em] mb-4">The Team Behind</span>
                        <div className="flex items-center gap-2 mb-6">
                            <img src={LogoBlack} alt="EP Logo" className="h-8 object-contain grayscale brightness-0" />
                        </div>
                        <div className="h-0.5 w-16 bg-[#436235]"></div>
                    </div>

                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-10">
                        {teamMembers.map((member) => (
                            <div
                                key={member.slug}
                                className="group relative rounded-2xl overflow-hidden shadow-2xl bg-white aspect-[4/5] transition-all duration-500 hover:-translate-y-1.5 hover:shadow-[0_25px_50px_-12px_rgba(67,98,53,0.45)]"
                            >
                                <img
                                    src={member.image}
                                    alt={member.name}
                                    className="absolute inset-0 w-full h-full object-cover transition-all duration-700 group-hover:scale-105"
                                />

                                {/* Whole-card click target → full profile (sits behind the quick icons) */}
                                <Link
                                    href={`/team/${member.slug}`}
                                    aria-label={`View ${member.name}'s profile`}
                                    className="absolute inset-0 z-10"
                                />

                                {/* Overlay: non-interactive except the quick-link icons */}
                                <div className="pointer-events-none absolute inset-x-0 bottom-0 z-20 p-7 pt-20 bg-gradient-to-t from-black/85 via-black/45 to-transparent">
                                    <h3 className="text-lg font-bold text-white mb-1 uppercase tracking-tight leading-tight">
                                        {member.name}
                                    </h3>
                                    <p className="text-[10px] text-gray-300 font-medium uppercase tracking-widest">
                                        {member.role}
                                    </p>

                                    {/* Quick links */}
                                    <div className="mt-4 flex items-center gap-2">
                                        {member.links.booking ? (
                                            <a href={member.links.booking} target="_blank" rel="noopener noreferrer" aria-label="Book a call" className={QUICK_ICON_CLASS}>
                                                <Calendar className="w-3.5 h-3.5" />
                                            </a>
                                        ) : null}
                                        {member.links.whatsapp ? (
                                            <a href={`https://wa.me/${waNumber(member.links.whatsapp)}`} target="_blank" rel="noopener noreferrer" aria-label="WhatsApp" className={QUICK_ICON_CLASS}>
                                                <MessageCircle className="w-3.5 h-3.5" />
                                            </a>
                                        ) : null}
                                        {member.links.email ? (
                                            <a href={`mailto:${member.links.email}`} aria-label="Email" className={QUICK_ICON_CLASS}>
                                                <Mail className="w-3.5 h-3.5" />
                                            </a>
                                        ) : null}
                                        {member.links.facebook ? (
                                            <a href={member.links.facebook} target="_blank" rel="noopener noreferrer" aria-label="Facebook" className={QUICK_ICON_CLASS}>
                                                <Facebook className="w-3.5 h-3.5" />
                                            </a>
                                        ) : null}
                                        {member.links.linkedin ? (
                                            <a href={member.links.linkedin} target="_blank" rel="noopener noreferrer" aria-label="LinkedIn" className={QUICK_ICON_CLASS}>
                                                <Linkedin className="w-3.5 h-3.5" />
                                            </a>
                                        ) : null}
                                    </div>

                                    <span className="mt-3 inline-flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-widest text-white/70 transition-colors group-hover:text-white">
                                        View profile <ArrowRight className="w-3 h-3" />
                                    </span>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            {/* Global Reach Section */}
            <section className="py-24 bg-white overflow-hidden">
                <div className="container mx-auto px-4 max-w-7xl">
                    <div className="mb-16 flex flex-col md:flex-row md:items-end md:justify-between gap-6">
                        <div>
                            <div className="flex items-center gap-4 mb-5">
                                <span className="text-[10px] font-bold text-[#436235] uppercase tracking-[0.3em]">Global Pathways</span>
                                <div className="h-[1px] w-12 bg-[#436235]/60"></div>
                            </div>
                            <h2 className="text-4xl md:text-5xl font-bold text-[#282728] uppercase tracking-tight leading-tight">
                                EP <br /><span className="text-[#436235]">Worldwide</span>
                            </h2>
                        </div>
                        <p className="text-gray-500 text-sm max-w-xs leading-relaxed">Click on any location pin to meet the local team and get in touch.</p>
                    </div>

                    {/* Map Container */}
                    <div className="relative w-full rounded-2xl overflow-hidden border border-gray-100 shadow-sm" style={{paddingBottom: '50%'}}>
                        {/* White background */}
                        <div className="absolute inset-0 bg-[#f7f8f6]"></div>

                        {/* Map SVG - dark gray silhouette */}
                        <img 
                            src="https://upload.wikimedia.org/wikipedia/commons/e/ec/World_map_blank_without_borders.svg" 
                            alt="World Map" 
                            className="absolute inset-0 w-full h-full object-contain pointer-events-none p-6"
                            style={{filter: 'brightness(0)', opacity: 0.12}}
                        />

                        {/* Location Pins */}
                        <div className="absolute inset-0">
                            {[
                                { 
                                    id: 'nz', name: "New Zealand", city: "Auckland", top: "72%", left: "87%",
                                    address: "Auckland, New Zealand", email: "admin@luvep.com", phone: "+64 21 000 0000",
                                    head: { name: "Dinah Jabone", image: DinaImg },
                                    team: [
                                        { name: "Dev Bhageerutty", role: "Licence Immigration Adviser (Provisional)", image: DevImg },
                                        { name: "Dinah Jabone", role: "Co-Founding Member", image: DinaImg },
                                        { name: "Emma Ceballo", role: "People Journey Experience Champion", image: EmmaImg },
                                        { name: "Emily Dela Pena", role: "Finance Admin Champion", image: EmilyImg },
                                        { name: "Hendry Dai", role: "Licence Immigration Adviser", image: DaiImg }
                                    ]
                                },
                                { 
                                    id: 'ph', name: "Philippines", city: "Digos City", top: "49%", left: "77%",
                                    address: "Digos City, Davao del Sur, Philippines", email: "hello@luvep.com", phone: "+63 900 000 0000",
                                    head: { name: "Neil Escaner", image: null },
                                    team: [
                                        { name: "Neil Escaner", role: "Booking Specialist", image: null },
                                        { name: "Bryll", role: "Booking Officer", image: BryllImg },
                                        { name: "Angelika Libanan", role: "Booking Coordinator", image: null }
                                    ]
                                },
                                { 
                                    id: 'in', name: "India", city: "New Delhi", top: "37%", left: "63%",
                                    address: "New Delhi, India", email: "india@luvep.com", phone: "+91 000 000 0000",
                                    head: { name: "Dinah Jabone", image: DinaImg },
                                    team: [
                                        { name: "Dinah Jabone", role: "Co-Founding Member", image: DinaImg }
                                    ]
                                },
                                { 
                                    id: 'my', name: "Malaysia", city: "Kuala Lumpur", top: "50%", left: "71%",
                                    address: "Kuala Lumpur, Malaysia", email: "my@luvep.com", phone: "+60 00 000 0000",
                                    head: { name: "Emily Dela Pena", image: EmilyImg },
                                    team: [
                                        { name: "Emily Dela Pena", role: "Finance Admin Champion", image: EmilyImg }
                                    ]
                                }
                            ].map((loc) => (
                                <div
                                    key={loc.id}
                                    className="absolute transform -translate-x-1/2 -translate-y-1/2 z-20"
                                    style={{ top: loc.top, left: loc.left }}
                                >
                                    <button
                                        onClick={() => setActiveLocation(loc)}
                                        className="relative group focus:outline-none flex flex-col items-center"
                                    >
                                        {/* Pulse ring */}
                                        <div className="absolute top-0 left-1/2 -translate-x-1/2 w-14 h-14 rounded-full border-2 border-[#436235]/30 animate-ping pointer-events-none"></div>

                                        {/* Photo avatar */}
                                        <div className={`relative w-12 h-12 rounded-full overflow-hidden border-[3px] shadow-lg transition-all duration-300 group-hover:scale-110 group-hover:shadow-xl ${
                                            activeLocation?.id === loc.id
                                                ? 'border-[#436235] scale-110 shadow-[0_0_0_4px_rgba(67,98,53,0.2)]'
                                                : 'border-white'
                                        }`}>
                                            <img
                                                src={loc.head.image || `https://ui-avatars.com/api/?background=436235&color=fff&size=100&bold=true&name=${encodeURIComponent(loc.head.name)}`}
                                                alt={loc.head.name}
                                                className="w-full h-full object-cover object-top"
                                            />
                                        </div>

                                        {/* Name + country pill — shows on hover */}
                                        <div className="mt-1.5 opacity-0 group-hover:opacity-100 -translate-y-1 group-hover:translate-y-0 transition-all duration-200 pointer-events-none">
                                            <div className="bg-[#282728] text-white text-[8px] font-bold uppercase tracking-widest px-2.5 py-1 rounded-full whitespace-nowrap shadow-lg">
                                                {loc.city}
                                            </div>
                                        </div>
                                    </button>
                                </div>
                            ))}
                        </div>

                        {/* Bottom legend */}
                        <div className="absolute bottom-4 left-6 flex items-center gap-3">
                            <div className="w-3 h-3 rounded-full bg-[#436235] border-2 border-white shadow"></div>
                            <span className="text-[9px] text-gray-400 uppercase tracking-widest font-bold">EP Office</span>
                        </div>
                    </div>

                    {/* Full Modal Popup */}
                    <AnimatePresence>
                        {activeLocation && (
                            <motion.div
                                initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}
                                className="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-black/70 backdrop-blur-md"
                                onClick={() => setActiveLocation(null)}
                            >
                                <motion.div
                                    initial={{ scale: 0.9, y: 30, opacity: 0 }} 
                                    animate={{ scale: 1, y: 0, opacity: 1 }} 
                                    exit={{ scale: 0.9, y: 30, opacity: 0 }}
                                    transition={{ type: 'spring', stiffness: 300, damping: 30 }}
                                    className="bg-white rounded-[2rem] max-w-4xl w-full max-h-[90vh] overflow-hidden shadow-[0_40px_80px_rgba(0,0,0,0.4)] relative flex flex-col"
                                    onClick={(e) => e.stopPropagation()}
                                >
                                    {/* Dark Premium Header */}
                                    <div className="relative bg-[#1a2e1e] px-10 pt-10 pb-16 overflow-hidden flex-shrink-0">
                                        {/* Decorative background pattern */}
                                        <div className="absolute inset-0 opacity-5">
                                            <div className="absolute top-0 right-0 w-96 h-96 rounded-full bg-[#436235] -translate-y-1/2 translate-x-1/2"></div>
                                            <div className="absolute bottom-0 left-0 w-64 h-64 rounded-full bg-[#00A693] translate-y-1/2 -translate-x-1/2"></div>
                                        </div>

                                        {/* Close button */}
                                        <button 
                                            onClick={() => setActiveLocation(null)} 
                                            className="absolute top-6 right-6 w-10 h-10 rounded-full bg-white/10 hover:bg-white/20 text-white transition-all flex items-center justify-center"
                                        >
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round"><path d="M18 6L6 18M6 6l12 12"></path></svg>
                                        </button>

                                        {/* Location tag */}
                                        <div className="inline-flex items-center gap-2 bg-[#436235]/40 px-4 py-2 rounded-full mb-5">
                                            <div className="w-2 h-2 rounded-full bg-[#00A693] animate-pulse"></div>
                                            <span className="text-[10px] font-bold uppercase tracking-[0.25em] text-[#a8d5a2]">{activeLocation.address}</span>
                                        </div>

                                        <h3 className="text-4xl md:text-5xl font-bold text-white uppercase tracking-tight leading-none">
                                            {activeLocation.name}
                                            <span className="block text-[#436235] mt-1">Team</span>
                                        </h3>
                                    </div>

                                    {/* Body - scrollable */}
                                    <div className="overflow-y-auto flex-1 -mt-8">
                                        {/* Team Grid */}
                                        <div className="px-8 md:px-10 pb-8">
                                            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-5">
                                                {activeLocation.team.map((member, idx) => (
                                                    <motion.div 
                                                        key={idx} 
                                                        initial={{ opacity: 0, y: 20 }}
                                                        animate={{ opacity: 1, y: 0 }}
                                                        transition={{ delay: idx * 0.07 }}
                                                        className="group relative rounded-2xl overflow-hidden bg-white shadow-md hover:shadow-xl border border-gray-100 hover:border-[#436235]/30 transition-all duration-300 cursor-default"
                                                    >
                                                        {/* Square Portrait Photo */}
                                                        <div className="aspect-[3/4] overflow-hidden bg-[#282728]">
                                                            <img 
                                                                src={member.image || `https://ui-avatars.com/api/?background=282728&color=ffffff&size=300&bold=true&font-size=0.33&name=${encodeURIComponent(member.name)}`} 
                                                                alt={member.name} 
                                                                className="w-full h-full object-cover object-top group-hover:scale-105 transition-transform duration-500"
                                                            />
                                                        </div>
                                                        {/* Name bar */}
                                                        <div className="p-4 bg-white">
                                                            <h4 className="text-sm font-bold text-[#282728] leading-tight mb-1">{member.name}</h4>
                                                            <div className="flex items-center gap-1.5">
                                                                <div className="w-1.5 h-1.5 rounded-full bg-[#436235] flex-shrink-0"></div>
                                                                <p className="text-[9px] text-gray-400 uppercase tracking-widest font-semibold leading-tight">{member.role}</p>
                                                            </div>
                                                        </div>
                                                    </motion.div>
                                                ))}
                                            </div>
                                        </div>

                                        {/* Contact Bar */}
                                        <div className="border-t border-gray-100 bg-[#f8faf8] px-8 md:px-10 py-7">
                                            <h4 className="text-[9px] font-bold text-gray-400 uppercase tracking-[0.3em] mb-5">Get In Touch</h4>
                                            <div className="flex flex-col sm:flex-row gap-4">
                                                <a href={`mailto:${activeLocation.email}`} className="group flex items-center gap-4 bg-white px-6 py-4 rounded-xl border border-gray-200 hover:border-[#436235] hover:shadow-md transition-all flex-1">
                                                    <div className="w-10 h-10 rounded-xl bg-[#436235]/10 flex items-center justify-center text-[#436235] group-hover:bg-[#436235] group-hover:text-white transition-all">
                                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                                                    </div>
                                                    <div>
                                                        <div className="text-[9px] text-gray-400 uppercase tracking-widest mb-0.5">Email</div>
                                                        <span className="text-sm font-bold text-[#282728]">{activeLocation.email}</span>
                                                    </div>
                                                </a>
                                                <a href={`tel:${activeLocation.phone}`} className="group flex items-center gap-4 bg-white px-6 py-4 rounded-xl border border-gray-200 hover:border-[#436235] hover:shadow-md transition-all flex-1">
                                                    <div className="w-10 h-10 rounded-xl bg-[#436235]/10 flex items-center justify-center text-[#436235] group-hover:bg-[#436235] group-hover:text-white transition-all">
                                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                                                    </div>
                                                    <div>
                                                        <div className="text-[9px] text-gray-400 uppercase tracking-widest mb-0.5">Phone</div>
                                                        <span className="text-sm font-bold text-[#282728]">{activeLocation.phone}</span>
                                                    </div>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </motion.div>
                            </motion.div>
                        )}
                    </AnimatePresence>
                </div>
            </section>

            {/* Learn About Section */}
            <LearnAbout />

            <ScrollToTop />
            <Footer />
        </div>
    );
}
