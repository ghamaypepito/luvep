import React from "react";
import Logo from "@assets/ep_only.png";
import IcefLogo from "@assets/about_us/icef.png";
import { Instagram, Facebook, Linkedin, MessageCircle } from "lucide-react";
import { usePage } from "@inertiajs/react";

export default function Footer() {
  return <PublicFooter contact={usePage().props?.contact} />;
}

export function PublicFooter({ contact = {} }) {
  const currentYear = new Date().getFullYear();

  const whatsappHref = contact.whatsapp
    ? `https://wa.me/${String(contact.whatsapp).replace(/[^\d]/g, "")}`
    : null;
  const facebookHref = contact.facebook || "https://www.facebook.com/epathwaysnz";
  const messengerHref = contact.messenger || "https://m.me/epathwaysnz";

  return (
    <footer className="font-urbanist">
      {/* Main Footer Section - White Background */}
      <div className="bg-white text-black py-20 px-6 md:px-12 lg:px-20 border-t border-gray-100">
        <div className="max-w-7xl mx-auto flex flex-col md:flex-row justify-between gap-10 md:gap-4 lg:gap-10">

          <div className="md:w-1/4">
            <div className="flex flex-col items-center md:items-start space-y-8">
              <a href="/" className="block w-48">
                <img src={Logo} alt="EP Logo" className="w-full" />
              </a>
              <div className="w-72">
                <img src={IcefLogo} alt="ICEF Logo" className="w-full" />
              </div>
              <div className="flex space-x-5 text-gray-800">
                <a href="https://instagram.com/nz.epathways" target="_blank" rel="noopener noreferrer" aria-label="Instagram" className="hover:text-green-600 transition-colors">
                  <Instagram size={24} strokeWidth={1.5} />
                </a>
                <a href={facebookHref} target="_blank" rel="noopener noreferrer" aria-label="Facebook" className="hover:text-green-600 transition-colors">
                  <Facebook size={24} strokeWidth={1.5} />
                </a>
                {messengerHref && (
                  <a href={messengerHref} target="_blank" rel="noopener noreferrer" aria-label="Messenger" title="Chat on Messenger" className="hover:text-green-600 transition-colors">
                    {/* Outline-style Messenger glyph at the same visual
                        weight as the other lucide icons (24px, stroke 1.5). */}
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                      <path d="M12 2C6.48 2 2 6.1 2 11.21c0 2.92 1.43 5.51 3.66 7.2V22l3.36-1.84c.9.25 1.86.38 2.85.38 5.52 0 10-4.1 10-9.21S17.52 2 12 2z" />
                      <path d="M6.96 14.36l3.05-3.24 2.36 2.36 4.84-2.6-3.05 3.24-2.36-2.36-4.84 2.6z" />
                    </svg>
                  </a>
                )}
                {whatsappHref && (
                  <a href={whatsappHref} target="_blank" rel="noopener noreferrer" aria-label="WhatsApp" className="hover:text-green-600 transition-colors">
                    <MessageCircle size={24} strokeWidth={1.5} />
                  </a>
                )}
                <a href="https://www.linkedin.com/company/epathways" target="_blank" rel="noopener noreferrer" aria-label="LinkedIn" className="hover:text-green-600 transition-colors">
                  <Linkedin size={24} strokeWidth={1.5} />
                </a>
              </div>
            </div>
          </div>

          {/* Column 2: Education */}
          <div className="space-y-6">
            <h3 className="text-lg font-bold">Education</h3>
            <ul className="space-y-4 text-gray-600 text-sm font-medium">
              <li><a href="/programs-levels" className="hover:text-green-600 transition-colors">Program and Levels</a></li>
              <li><a href="/fee-guide" className="hover:text-green-600 transition-colors">Fee Guide</a></li>
              <li><a href="/education-journey" className="hover:text-green-600 transition-colors">Journey Experience</a></li>
              <li><a href="/free-assessment" className="hover:text-green-600 transition-colors">Free Assessment</a></li>
            </ul>
          </div>

          {/* Column 3: Immigration */}
          <div className="space-y-6">
            <h3 className="text-lg font-bold">Immigration</h3>
            <ul className="space-y-4 text-gray-600 text-sm font-medium">
              <li><a href="/immigration" className="hover:text-green-600 transition-colors">Overview</a></li>
              <li><a href="/immigration-assessment" className="hover:text-green-600 transition-colors">Visa pathways</a></li>
              <li><a href="/resident-interest" className="hover:text-green-600 transition-colors">Resident interest</a></li>
              <li><a href="/visa-approved" className="hover:text-green-600 transition-colors">Approval stories</a></li>
            </ul>
          </div>

          {/* Column 4: Accommodation */}
          <div className="space-y-6">
            <h3 className="text-lg font-bold">Accommodation</h3>
            <ul className="space-y-4 text-gray-600 text-sm font-medium">
              <li><a href="/accommodation" className="hover:text-green-600 transition-colors">Browse stays</a></li>
              <li><a href="/booking" className="hover:text-green-600 transition-colors">Talk to us</a></li>
            </ul>
          </div>

          {/* Column 5: Contact Us */}
          <div className="space-y-6">
            <h3 className="text-lg font-bold">Contact us</h3>
            <div className="space-y-6 text-gray-600 text-sm font-medium">
              <div>
                <p className="font-bold text-black mb-1">Call :</p>
                <a href={`tel:${(contact.phone || "+64277775586").replace(/\s+/g, "")}`} className="hover:text-green-600 transition-colors flex items-center gap-2">
                  {contact.phone || "+64 27 777 5586"}
                </a>
              </div>

              {whatsappHref && (
                <div>
                  <p className="font-bold text-black mb-1">WhatsApp :</p>
                  <a href={whatsappHref} target="_blank" rel="noopener noreferrer" className="hover:text-green-600 transition-colors">
                    Message us
                  </a>
                </div>
              )}

              <div>
                <p className="font-bold text-black mb-1">Email :</p>
                <a href={`mailto:${contact.email || "info@luvep.com"}`} className="hover:text-green-600 transition-colors flex items-center gap-2">
                  {contact.email || "info@luvep.com"}
                </a>
              </div>

              <div>
                <p className="font-bold text-black mb-1">Location :</p>
                <p>Auckland, New Zealand</p>
              </div>
            </div>
          </div>

        </div>
      </div>

      {/* Bottom Bar - Dark Background */}
      <div className="bg-[#282728] text-white py-6 border-t border-gray-800">
        <div className="max-w-7xl mx-auto px-6 md:px-12 lg:px-20 flex flex-col md:flex-row justify-between items-center text-sm">
          <nav aria-label="Footer" className="flex flex-wrap items-center justify-center gap-x-5 gap-y-2 mb-4 sm:gap-x-6 md:mb-0">
            <a href="/about-us" className="hover:text-green-400 transition-colors">About Us</a>
            <a href="/booking" className="hover:text-green-400 transition-colors">Contact</a>
            <a href="/activities" className="hover:text-green-400 transition-colors">Activities</a>
            <a href="/articles" className="hover:text-green-400 transition-colors">Articles</a>
          </nav>
          <div className="text-gray-400">
            © {currentYear} eP. All rights reserved.
          </div>
        </div>
      </div>
    </footer>
  );
}
