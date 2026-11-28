import React, { useState, useEffect } from 'react';
import { Head } from '@inertiajs/react';
import { motion, AnimatePresence } from 'framer-motion';
import Navbar from '@/components/layout/Navbar';
import Footer from '@/components/layout/Footer';

const PropertyDetails = ({ property }) => {
  // Return the user to where they were (preserves their scroll on the listing).
  // Falls back to the listings section if they opened this page directly.
  const goBack = () => {
    if (window.history.length > 1) {
      window.history.back();
    } else {
      window.location.href = '/accommodation#properties';
    }
  };
  const images = property?.images?.map((i) => i.url) ?? [];
  const money = (v) => (v == null ? null : `$${Number(v).toFixed(0)}`);

  const [activeImage, setActiveImage] = useState(0);
  const [lightboxOpen, setLightboxOpen] = useState(false);
  const prevImage = () => setActiveImage((i) => (i - 1 + images.length) % images.length);
  const nextImage = () => setActiveImage((i) => (i + 1) % images.length);

  // Keyboard controls + lock page scroll while the fullscreen gallery is open.
  useEffect(() => {
    if (!lightboxOpen) return;
    const onKey = (e) => {
      if (e.key === 'Escape') setLightboxOpen(false);
      if (e.key === 'ArrowLeft') prevImage();
      if (e.key === 'ArrowRight') nextImage();
    };
    document.addEventListener('keydown', onKey);
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = '';
    };
  }, [lightboxOpen, images.length]);

  const isWholeProperty = property?.rental_mode === 'whole_property';

  const features = isWholeProperty
    ? [
        { name: 'Bedrooms', value: property?.bedrooms },
        { name: 'Bathrooms', value: property?.bathrooms },
        { name: 'Whole property', value: 'Yes' },
      ].filter((f) => f.value != null && f.value !== '')
    : [
        { name: 'Room type', value: property?.room_type, cap: true },
        { name: 'Bed', value: property?.bed_type ? `${property.bed_type} mattress` : null, cap: true },
        { name: 'Wardrobe', value: property?.has_wardrobe ? 'Yes' : 'No' },
        { name: 'Bathroom', value: property?.bathroom_type, cap: true },
      ].filter((f) => f.value);

  // Embeddable Google map — use the precise pin from a pasted Maps link if
  // available, otherwise fall back to the suburb / location.
  const mapSrc = (() => {
    const url = property?.map_url;
    if (url) {
      const place = url.match(/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/);
      const at = url.match(/@(-?\d+\.\d+),(-?\d+\.\d+)/);
      const coords = place ? `${place[1]},${place[2]}` : at ? `${at[1]},${at[2]}` : null;
      if (coords) return `https://maps.google.com/maps?q=${coords}&z=16&output=embed`;
    }
    const q = [property?.location, property?.suburb].filter(Boolean).join(', ');
    return q ? `https://maps.google.com/maps?q=${encodeURIComponent(q)}&z=14&output=embed` : null;
  })();

  return (
    <div className="bg-[#fafafa] min-h-screen font-urbanist text-black selection:bg-[#1F5A8B] selection:text-white">
      <Navbar />

      <main className="container mx-auto px-4 py-12 max-w-7xl">
        <Head title={property?.name ?? 'Accommodation'} />

        {/* Back button */}
        <button
          type="button"
          onClick={goBack}
          className="inline-flex items-center gap-2 text-sm font-semibold text-gray-500 hover:text-black mb-6 transition-colors"
        >
          <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M15 19l-7-7 7-7" /></svg>
          Back to accommodation
        </button>

        {/* Header */}
        <div className="mb-8 flex flex-col md:flex-row justify-between items-start md:items-end gap-6">
          <div>
            <div className="flex items-center space-x-3 mb-3">
              <span className="px-3 py-1 bg-gray-100 text-xs font-bold uppercase tracking-wider rounded-full capitalize">
                {isWholeProperty ? 'Whole property' : `${property?.room_type ?? ''} room`}
              </span>
              {(property?.suburb || property?.location) && (
                <span className="text-sm text-gray-500 font-medium">{[property?.suburb, property?.location].filter(Boolean).join(' · ')}</span>
              )}
            </div>
            <h1 className="text-4xl md:text-5xl font-bold tracking-tight mb-2">{property?.name}</h1>
            {property?.description && <p className="text-gray-500 text-lg">{property.description}</p>}
          </div>
        </div>

        {/* Image gallery — one large image + clickable thumbnails */}
        {images.length > 0 && (
          <div className="mb-16">
            {/* Main image */}
            <div className="relative w-full h-[360px] sm:h-[460px] md:h-[560px] rounded-[2rem] overflow-hidden bg-gray-100">
              <img
                src={images[activeImage]}
                alt={property?.name}
                onClick={() => setLightboxOpen(true)}
                className="w-full h-full object-cover cursor-zoom-in"
              />

              <button
                type="button"
                onClick={() => setLightboxOpen(true)}
                className="absolute bottom-5 left-5 inline-flex items-center gap-2.5 bg-white text-black text-sm font-bold px-5 py-3 rounded-full shadow-lg hover:scale-105 hover:shadow-xl transition-all duration-200"
              >
                <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M4 8V4h4M16 4h4v4M20 16v4h-4M8 20H4v-4" /></svg>
                View all {images.length} photo{images.length === 1 ? '' : 's'}
              </button>

              {images.length > 1 && (
                <>
                  <button
                    type="button"
                    aria-label="Previous image"
                    onClick={prevImage}
                    className="absolute left-4 top-1/2 -translate-y-1/2 w-11 h-11 rounded-full bg-white/90 backdrop-blur shadow-md flex items-center justify-center text-black hover:bg-white transition-colors"
                  >
                    <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M15 19l-7-7 7-7" /></svg>
                  </button>
                  <button
                    type="button"
                    aria-label="Next image"
                    onClick={nextImage}
                    className="absolute right-4 top-1/2 -translate-y-1/2 w-11 h-11 rounded-full bg-white/90 backdrop-blur shadow-md flex items-center justify-center text-black hover:bg-white transition-colors"
                  >
                    <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7" /></svg>
                  </button>
                  <div className="absolute bottom-4 right-4 bg-black/60 text-white text-xs font-semibold px-3 py-1.5 rounded-full">
                    {activeImage + 1} / {images.length}
                  </div>
                </>
              )}
            </div>

            {/* Thumbnails */}
            {images.length > 1 && (
              <div className="mt-4 flex gap-3 overflow-x-auto pb-1">
                {images.map((img, i) => (
                  <button
                    key={i}
                    type="button"
                    onClick={() => setActiveImage(i)}
                    className={`relative h-20 w-28 shrink-0 rounded-2xl overflow-hidden border-2 transition ${
                      i === activeImage ? 'border-[#1F5A8B]' : 'border-transparent opacity-60 hover:opacity-100'
                    }`}
                  >
                    <img src={img} alt={`View ${i + 1}`} className="w-full h-full object-cover" />
                  </button>
                ))}
              </div>
            )}
          </div>
        )}

        {/* Content */}
        <div className="flex flex-col lg:flex-row gap-16">
          <div className="lg:w-2/3 space-y-12">
            {property?.includes && (
              <div>
                <h3 className="text-2xl font-bold mb-4">About this space</h3>
                <p className="text-gray-600 leading-relaxed text-lg">{property.includes}</p>
              </div>
            )}

            <div>
              <h3 className="text-2xl font-bold mb-6">What this place offers</h3>
              <div className="grid grid-cols-2 md:grid-cols-3 gap-6">
                {features.map((feature, idx) => (
                  <div key={idx} className="flex flex-col space-y-1 p-4 bg-gray-50 rounded-2xl">
                    <span className="text-xs font-bold text-gray-400 uppercase tracking-wider">{feature.name}</span>
                    <span className={`font-semibold ${feature.cap ? 'capitalize' : ''}`}>{feature.value}</span>
                  </div>
                ))}
              </div>
            </div>

            {isWholeProperty && Array.isArray(property?.rooms_layout) && property.rooms_layout.length > 0 && (
              <div>
                <h3 className="text-2xl font-bold mb-6">Rooms</h3>
                <div className="overflow-x-auto rounded-2xl border border-gray-100">
                  <table className="w-full text-sm">
                    <thead className="bg-gray-50 text-left text-[11px] uppercase tracking-wider text-gray-500">
                      <tr>
                        <th className="px-4 py-3 font-semibold">Room</th>
                        <th className="px-4 py-3 font-semibold">Type</th>
                        <th className="px-4 py-3 font-semibold">Bed</th>
                        <th className="px-4 py-3 font-semibold">Ensuite</th>
                        <th className="px-4 py-3 font-semibold">Notes</th>
                      </tr>
                    </thead>
                    <tbody>
                      {property.rooms_layout.map((row, idx) => (
                        <tr key={idx} className="border-t border-gray-100">
                          <td className="px-4 py-3 font-semibold text-gray-900">{row.name || '—'}</td>
                          <td className="px-4 py-3 text-gray-700">{row.type || '—'}</td>
                          <td className="px-4 py-3 text-gray-700">{row.bed || '—'}</td>
                          <td className="px-4 py-3 text-gray-700">{row.ensuite || '—'}</td>
                          <td className="px-4 py-3 text-gray-500">{row.notes || '—'}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            )}
          </div>

          {/* Sidebar */}
          <div className="lg:w-1/3">
            <div className="sticky top-28 bg-white p-8 rounded-[2rem] shadow-[0_8px_30px_rgb(0,0,0,0.08)] border border-gray-100">
              <div className="mb-6 rounded-2xl border border-[#1F5A8B]/15 bg-[#1F5A8B]/5 p-5">
                {isWholeProperty ? (
                  <>
                    <div className="flex items-baseline justify-between gap-2">
                      <span className="text-[11px] font-bold uppercase tracking-[0.15em] text-[#1F5A8B]">Whole property</span>
                      <span className="text-4xl font-extrabold text-[#282728] leading-none">
                        {money(property?.whole_property_rent_weekly)}<span className="text-sm text-gray-400 font-medium">/wk</span>
                      </span>
                    </div>
                    <p className="text-[11px] text-gray-400 mt-4">
                      {[property?.bedrooms ? `${property.bedrooms} bed` : null, property?.bathrooms ? `${property.bathrooms} bath` : null].filter(Boolean).join(' · ') || 'entire home'}
                    </p>
                  </>
                ) : (
                  <>
                    <div className="flex items-baseline justify-between gap-2">
                      <span className="text-[11px] font-bold uppercase tracking-[0.15em] text-[#1F5A8B]">Single</span>
                      <span className="text-4xl font-extrabold text-[#282728] leading-none">
                        {money(property?.rent_single)}<span className="text-sm text-gray-400 font-medium">/wk</span>
                      </span>
                    </div>
                    {property?.rent_couple != null && (
                      <div className="mt-4 pt-4 border-t border-[#1F5A8B]/15 flex items-baseline justify-between gap-2">
                        <span className="text-[11px] font-bold uppercase tracking-[0.15em] text-[#1F5A8B]">Couple</span>
                        <span className="text-3xl font-extrabold text-[#282728] leading-none">
                          {money(property.rent_couple)}<span className="text-sm text-gray-400 font-medium">/wk</span>
                        </span>
                      </div>
                    )}
                    <p className="text-[11px] text-gray-400 mt-4">single occupant{property?.bills_excluded ? ' · excludes bills' : ''}</p>
                  </>
                )}
              </div>

              <a href={`/accommodation/expression-of-interest-hot?listing=${property.id}`} className="w-full py-4 bg-[#1F5A8B] text-white rounded-full font-bold hover:bg-[#184A73] transition-colors shadow-lg shadow-[#1F5A8B]/20 text-center block">
                Interested? Click here
              </a>
              <p className="text-center text-xs text-gray-500 mt-4">We'll get back to you within 24 hours</p>
            </div>
          </div>
        </div>

        {mapSrc && (
          <div className="mt-16">
            <h3 className="text-2xl font-bold mb-6">Location</h3>
            <div className="rounded-[2rem] overflow-hidden border border-gray-100 h-[400px]">
              <iframe
                src={mapSrc}
                title="Property location"
                className="w-full h-full"
                style={{ border: 0 }}
                loading="lazy"
                allowFullScreen
                referrerPolicy="no-referrer-when-downgrade"
              ></iframe>
            </div>
          </div>
        )}
      </main>

      <Footer />

      {/* Fullscreen gallery (lightbox) */}
      <AnimatePresence>
        {lightboxOpen && images.length > 0 && (
          <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            transition={{ duration: 0.2 }}
            className="fixed inset-0 z-[100] bg-black/95 flex flex-col"
            onClick={() => setLightboxOpen(false)}
          >
          {/* Top bar */}
          <div className="flex items-center justify-between px-4 sm:px-6 py-4 text-white" onClick={(e) => e.stopPropagation()}>
            <span className="text-sm font-semibold">{activeImage + 1} / {images.length}</span>
            <button type="button" aria-label="Close gallery" onClick={() => setLightboxOpen(false)} className="p-2 rounded-full hover:bg-white/10 transition-colors">
              <svg className="w-6 h-6" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
          </div>

          {/* Main image */}
          <div className="relative flex-1 flex items-center justify-center px-4 sm:px-16 min-h-0">
            {images.length > 1 && (
              <button type="button" aria-label="Previous image" onClick={(e) => { e.stopPropagation(); prevImage(); }} className="absolute left-3 sm:left-6 top-1/2 -translate-y-1/2 w-11 h-11 rounded-full bg-white/15 hover:bg-white/25 text-white flex items-center justify-center transition-colors">
                <svg className="w-6 h-6" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M15 19l-7-7 7-7" /></svg>
              </button>
            )}

            <motion.img
              key={activeImage}
              initial={{ opacity: 0, scale: 0.97 }}
              animate={{ opacity: 1, scale: 1 }}
              transition={{ duration: 0.25 }}
              src={images[activeImage]}
              alt={property?.name}
              onClick={(e) => e.stopPropagation()}
              className="max-h-full max-w-full object-contain rounded-lg"
            />

            {images.length > 1 && (
              <button type="button" aria-label="Next image" onClick={(e) => { e.stopPropagation(); nextImage(); }} className="absolute right-3 sm:right-6 top-1/2 -translate-y-1/2 w-11 h-11 rounded-full bg-white/15 hover:bg-white/25 text-white flex items-center justify-center transition-colors">
                <svg className="w-6 h-6" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7" /></svg>
              </button>
            )}
          </div>

          {/* Thumbnail strip */}
          {images.length > 1 && (
            <div className="px-4 py-4 flex gap-2 overflow-x-auto sm:justify-center" onClick={(e) => e.stopPropagation()}>
              {images.map((img, i) => (
                <button
                  key={i}
                  type="button"
                  onClick={() => setActiveImage(i)}
                  className={`h-14 w-20 shrink-0 rounded-lg overflow-hidden border-2 transition ${
                    i === activeImage ? 'border-white' : 'border-transparent opacity-50 hover:opacity-100'
                  }`}
                >
                  <img src={img} alt={`View ${i + 1}`} className="w-full h-full object-cover" />
                </button>
              ))}
            </div>
          )}
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  );
};

export default PropertyDetails;
