import React, { useState, useEffect, useMemo } from 'react';
import { Swiper, SwiperSlide } from 'swiper/react';
import { Autoplay } from 'swiper/modules';
import 'swiper/css';
import Navbar from '@/components/layout/Navbar';
import Footer from '@/components/layout/Footer';
import PropertyViewingBooking from '@/components/accommodation/PropertyViewingBooking';

// Split the free-text "includes" into bullets on commas — but ignore commas
// inside parentheses, e.g. "Shared common areas (fridge, dining table)" stays one item.
function splitIncludes(text) {
  if (!text) return [];
  const items = [];
  let depth = 0;
  let current = '';
  for (const ch of text) {
    if (ch === '(') depth++;
    else if (ch === ')') depth = Math.max(0, depth - 1);
    if (ch === ',' && depth === 0) {
      if (current.trim()) items.push(current.trim());
      current = '';
    } else {
      current += ch;
    }
  }
  if (current.trim()) items.push(current.trim());
  return items;
}

const Accommodation = ({ properties = [], bookingTimezone = 'Pacific/Auckland' }) => {
  const [search, setSearch] = useState('');
  const [roomFilter, setRoomFilter] = useState('all');
  const [bedFilter, setBedFilter] = useState('all');
  const [suburbFilter, setSuburbFilter] = useState('all');
  const [propertyTypeFilter, setPropertyTypeFilter] = useState('all');

  const money = (v) => (v == null ? null : `$${Number(v).toFixed(0)}`);

  // Suburbs present in the current listings (drives the location dropdown).
  const suburbs = [...new Set(properties.map((p) => p.suburb).filter(Boolean))].sort();

  // Hero carousel — ALL property images, randomized (falls back to a stock photo).
  const heroImages = useMemo(() => {
    const imgs = properties.flatMap((p) => (p.images?.map((i) => i.url) ?? [])).filter(Boolean);
    const pool = imgs.length > 0 ? imgs : ['https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?ixlib=rb-4.0.3&auto=format&fit=crop&w=1200&q=80'];
    return pool
      .map((url) => ({ url, sort: Math.random() }))
      .sort((a, b) => a.sort - b.sort)
      .map((x) => x.url);
  }, [properties]);

  const [heroIndex, setHeroIndex] = useState(0);
  useEffect(() => {
    if (heroImages.length <= 1) return;
    const t = setInterval(() => setHeroIndex((i) => (i + 1) % heroImages.length), 3500);
    return () => clearInterval(t);
  }, [heroImages]);

  const [swiperRef, setSwiperRef] = useState(null);

  // Repeat the list so Swiper's loop always has enough slides (>= 8) to cycle
  // smoothly even with only a few listings — otherwise it sticks at the end.
  const carouselItems = useMemo(() => {
    if (properties.length === 0) return [];
    const items = [...properties];
    while (items.length < 8) items.push(...properties);
    return items;
  }, [properties]);

  const GridImageCard = ({ property }) => (
    <a href={`/accommodation/${property.slug}`} className="space-y-4 cursor-pointer group block">
      <div className="relative rounded-[2rem] overflow-hidden h-64 bg-gray-100">
        {property.cover_image ? (
          <img src={property.cover_image} alt={property.name} className="w-full h-full object-cover transition-transform duration-700 group-hover:scale-105" />
        ) : (
          <div className="w-full h-full flex items-center justify-center text-gray-300 text-sm">No image</div>
        )}
      </div>
      <div className="flex justify-between items-center px-1 gap-2">
        <span className="font-bold text-sm truncate">{property.name}</span>
        <span className="text-gray-400 text-[11px] font-medium shrink-0">{property.suburb || property.location || 'New Zealand'}</span>
      </div>
    </a>
  );

  const filtered = properties.filter((p) => {
    const roomOk = roomFilter === 'all' || p.room_type === roomFilter;
    const bedOk = bedFilter === 'all' || p.bed_type === bedFilter;
    const suburbOk = suburbFilter === 'all' || p.suburb === suburbFilter;
    const propertyTypeOk = propertyTypeFilter === 'all' || p.property_type === propertyTypeFilter;
    const q = search.trim().toLowerCase();
    const searchOk = !q || [p.name, p.suburb, p.location, p.includes]
      .filter(Boolean)
      .some((v) => v.toLowerCase().includes(q));
    return roomOk && bedOk && suburbOk && propertyTypeOk && searchOk;
  });

  return (
    <div className="bg-[#fafafa] min-h-screen font-urbanist text-black">
      <Navbar />

      {/* Book a viewing — property-based scheduler (mirrors the immigration
          consultation booking, but driven by accommodation properties). */}
      <section className="container mx-auto px-4 pt-12 lg:pt-16 max-w-5xl">
        <PropertyViewingBooking properties={properties} businessTz={bookingTimezone} />
      </section>

      {/* Hero Section */}
      <section className="container mx-auto px-4 py-16 lg:py-24 max-w-7xl">
        <div className="flex flex-col lg:flex-row gap-16 items-center">
          {/* Left Content */}
          <div className="lg:w-5/12 space-y-8">
            <div className="flex items-center space-x-2">
              <svg className="w-5 h-5 text-black" fill="currentColor" viewBox="0 0 20 20">
                <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
              </svg>
              <span className="font-semibold text-sm">4.5 Rating</span>
              <span className="text-gray-500 text-sm">by New York Times</span>
            </div>

            <h1 className="text-6xl lg:text-7xl font-bold tracking-tight leading-[1.1]">
              Find out the<br />Best stay.
            </h1>

            <p className="text-gray-500 max-w-sm text-sm leading-relaxed">
              Your content goes here. Edit or remove this text inline or in the module Content settings. You can also style every aspect of this content in the module Design.
            </p>

            <div className="flex items-center space-x-4 pt-2">
              <a
                href="#properties"
                onClick={(e) => {
                  e.preventDefault();
                  document.getElementById('properties')?.scrollIntoView({ behavior: 'smooth' });
                }}
                className="px-8 py-3.5 bg-gray-200/60 hover:bg-gray-200 text-black rounded-full text-sm font-bold transition-colors"
              >
                Browse Properties
              </a>
              <a
                href="/booking"
                className="px-8 py-3.5 bg-[#1F5A8B] hover:bg-[#184A73] text-white rounded-full text-sm font-bold transition-colors flex items-center space-x-2"
              >
                <span>Talk to us</span>
              </a>
            </div>

          </div>

          {/* Right Image — auto-rotating carousel of property images */}
          <div className="lg:w-7/12">
            <div className="relative w-full h-[550px] rounded-[2.5rem] overflow-hidden bg-gray-100">
              {heroImages.map((url, i) => (
                <img
                  key={i}
                  src={url}
                  alt="Featured accommodation"
                  className={`absolute inset-0 w-full h-full object-cover transition-opacity duration-1000 ease-in-out ${i === heroIndex ? 'opacity-100' : 'opacity-0'}`}
                />
              ))}
            </div>
          </div>
        </div>
      </section>

      {/* Grid Images Section */}
      <section className="container mx-auto px-4 py-8 max-w-7xl">
        {properties.length > 0 ? (
          <div className="relative">
            <Swiper
              onSwiper={setSwiperRef}
              modules={[Autoplay]}
              spaceBetween={24}
              slidesPerView={1.2}
              loop={true}
              speed={800}
              autoplay={{ delay: 2500, disableOnInteraction: false, pauseOnMouseEnter: true }}
              breakpoints={{
                640: { slidesPerView: 2 },
                768: { slidesPerView: 3 },
                1024: { slidesPerView: 4 },
              }}
            >
              {carouselItems.map((p, i) => (
                <SwiperSlide key={i}>
                  <GridImageCard property={p} />
                </SwiperSlide>
              ))}
            </Swiper>

            {properties.length > 1 && (
              <>
                <button
                  type="button"
                  aria-label="Previous"
                  onClick={() => swiperRef?.slidePrev()}
                  className="absolute left-0 top-32 -translate-y-1/2 z-10 -ml-2 lg:-ml-5 w-11 h-11 rounded-full bg-white shadow-[0_4px_20px_-4px_rgba(0,0,0,0.2)] flex items-center justify-center text-black hover:bg-gray-50 transition-colors"
                >
                  <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M15 19l-7-7 7-7" /></svg>
                </button>
                <button
                  type="button"
                  aria-label="Next"
                  onClick={() => swiperRef?.slideNext()}
                  className="absolute right-0 top-32 -translate-y-1/2 z-10 -mr-2 lg:-mr-5 w-11 h-11 rounded-full bg-white shadow-[0_4px_20px_-4px_rgba(0,0,0,0.2)] flex items-center justify-center text-black hover:bg-gray-50 transition-colors"
                >
                  <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7" /></svg>
                </button>
              </>
            )}
          </div>
        ) : (
          <p className="text-center text-gray-400 py-8">No properties to show yet.</p>
        )}
      </section>

      {/* Amenities Overview Section */}
      <section className="bg-white py-24">
        <div className="container mx-auto px-4 max-w-7xl text-center">
          <p className="text-[10px] font-bold tracking-[0.3em] text-gray-400 mb-6 uppercase">Why Choose Us</p>
          <h2 className="text-4xl md:text-5xl font-bold tracking-tight mb-16">Premium Amenities for Your Comfort</h2>
          
          <div className="grid grid-cols-1 md:grid-cols-3 gap-12">
            <div className="flex flex-col items-center space-y-4">
              <div className="w-16 h-16 bg-gray-50 rounded-full flex items-center justify-center mb-4">
                <svg className="w-8 h-8 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.5" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
              </div>
              <h3 className="text-xl font-bold">Prime Locations</h3>
              <p className="text-gray-500 text-sm leading-relaxed max-w-xs">All our properties are situated in the heart of Auckland, giving you easy access to the best attractions.</p>
            </div>
            
            <div className="flex flex-col items-center space-y-4">
              <div className="w-16 h-16 bg-gray-50 rounded-full flex items-center justify-center mb-4">
                <svg className="w-8 h-8 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8V7a4 4 0 00-8 0v4h8z"></path></svg>
              </div>
              <h3 className="text-xl font-bold">High Security</h3>
              <p className="text-gray-500 text-sm leading-relaxed max-w-xs">We ensure your safety with 24/7 surveillance and smart lock systems in all our premium accommodations.</p>
            </div>
            
            <div className="flex flex-col items-center space-y-4">
              <div className="w-16 h-16 bg-gray-50 rounded-full flex items-center justify-center mb-4">
                <svg className="w-8 h-8 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.5" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"></path></svg>
              </div>
              <h3 className="text-xl font-bold">Daily Cleaning</h3>
              <p className="text-gray-500 text-sm leading-relaxed max-w-xs">Enjoy hotel-like services with our professional daily housekeeping, ensuring your stay is always pristine.</p>
            </div>
          </div>
        </div>
      </section>

      {/* Main Accommodation Section */}
      <section className="container mx-auto px-4 py-24 max-w-7xl text-center scroll-mt-24" id="properties">
        <p className="text-[10px] font-bold tracking-[0.3em] text-gray-400 mb-6 uppercase">Accommodation Layout Pack</p>
        <h2 className="text-5xl md:text-6xl font-bold tracking-tight mb-16">accommodation</h2>

        {/* Filters */}
        <div className="max-w-4xl mx-auto mb-16 flex flex-col sm:flex-row flex-wrap justify-center gap-3">
          <div className="relative flex-1 min-w-[200px]">
            <svg className="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z" />
            </svg>
            <input
              type="text"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search by name, location or features…"
              className="w-full rounded-full border border-gray-200 bg-white pl-11 pr-5 py-3 text-xs font-bold text-gray-600 focus:border-black focus:ring-black"
            />
          </div>

          <select
            value={propertyTypeFilter}
            onChange={(e) => setPropertyTypeFilter(e.target.value)}
            className="rounded-full border border-gray-200 bg-white px-5 py-3 text-xs font-bold text-gray-600 cursor-pointer focus:border-black focus:ring-black"
          >
            <option value="all">All place types</option>
            <option value="House">House</option>
            <option value="Apartment">Apartment</option>
            <option value="Townhouse">Townhouse</option>
            <option value="Studio">Studio</option>
            <option value="Room">Room</option>
          </select>

          <select
            value={roomFilter}
            onChange={(e) => setRoomFilter(e.target.value)}
            className="rounded-full border border-gray-200 bg-white px-5 py-3 text-xs font-bold text-gray-600 cursor-pointer focus:border-black focus:ring-black"
          >
            <option value="all">All room types</option>
            <option value="single">Single</option>
            <option value="ensuite">Ensuite</option>
          </select>

          <select
            value={bedFilter}
            onChange={(e) => setBedFilter(e.target.value)}
            className="rounded-full border border-gray-200 bg-white px-5 py-3 text-xs font-bold text-gray-600 cursor-pointer focus:border-black focus:ring-black"
          >
            <option value="all">All bed types</option>
            <option value="single">Single bed mattress</option>
            <option value="double">Double bed mattress</option>
          </select>

          <select
            value={suburbFilter}
            onChange={(e) => setSuburbFilter(e.target.value)}
            className="rounded-full border border-gray-200 bg-white px-5 py-3 text-xs font-bold text-gray-600 cursor-pointer focus:border-black focus:ring-black"
          >
            <option value="all">All locations</option>
            {suburbs.map((s) => (
              <option key={s} value={s}>{s}</option>
            ))}
          </select>
        </div>

        {/* List of Properties */}
        <div className="space-y-8 text-left">
          {filtered.length === 0 ? (
            <div className="rounded-[2rem] bg-white p-16 text-center text-gray-400">
              No properties to show right now. Please check back soon.
            </div>
          ) : (
            filtered.map((acc) => (
              <div key={acc.id} className="bg-white rounded-[2rem] p-4 lg:p-6 grid grid-cols-1 lg:grid-cols-4 gap-5 items-center shadow-[0_4px_20px_-4px_rgba(0,0,0,0.03)] hover:shadow-[0_8px_30px_-4px_rgba(0,0,0,0.08)] transition-shadow">

                {/* Column 1: Image */}
                <div className="relative w-full h-64 lg:h-full min-h-[260px]">
                  {acc.cover_image ? (
                    <img src={acc.cover_image} alt={acc.name} className="w-full h-full object-cover rounded-[1.5rem]" />
                  ) : (
                    <div className="w-full h-full rounded-[1.5rem] bg-gray-100 flex items-center justify-center text-gray-300 text-sm">No image</div>
                  )}
                  <div className="absolute top-4 right-4 bg-white px-3 py-1.5 rounded-full text-xs font-bold shadow-sm capitalize">
                    {acc.rental_mode === 'whole_property' ? 'Whole property' : acc.room_type}
                  </div>
                </div>

                {/* Column 2: Title */}
                <div className="flex flex-col justify-between h-full py-4 lg:pr-6 lg:border-r border-gray-100">
                  <div>
                    <p className="text-[10px] font-bold tracking-[0.2em] text-gray-400 mb-2 uppercase">{[acc.suburb, acc.location].filter(Boolean).join(' · ') || 'New Zealand'}</p>
                    <h3 className="text-2xl lg:text-3xl font-bold leading-tight tracking-tight">{acc.name}</h3>
                    {acc.includes && (
                      <p className="text-[11px] text-gray-400 mt-3 leading-relaxed line-clamp-3">{acc.includes}</p>
                    )}
                  </div>
                </div>

                {/* Column 3: Rent & Info */}
                <div className="h-full py-4 lg:px-6 lg:border-r border-gray-100">
                  <div className="mb-6 rounded-2xl border border-[#1F5A8B]/15 bg-[#1F5A8B]/5 p-4">
                    {acc.rental_mode === 'whole_property' ? (
                      <>
                        <div className="flex items-baseline justify-between gap-2">
                          <span className="text-[10px] font-bold uppercase tracking-[0.15em] text-[#1F5A8B]">Whole property</span>
                          <span className="text-3xl font-extrabold text-[#282728] leading-none">
                            {money(acc.whole_property_rent_weekly)}<span className="text-xs text-gray-400 font-medium">/wk</span>
                          </span>
                        </div>
                        <p className="text-[10px] text-gray-400 mt-3">
                          {[acc.bedrooms ? `${acc.bedrooms} bed` : null, acc.bathrooms ? `${acc.bathrooms} bath` : null].filter(Boolean).join(' · ') || 'entire home'}
                        </p>
                      </>
                    ) : (
                      <>
                        <div className="flex items-baseline justify-between gap-2">
                          <span className="text-[10px] font-bold uppercase tracking-[0.15em] text-[#1F5A8B]">Single</span>
                          <span className="text-3xl font-extrabold text-[#282728] leading-none">
                            {money(acc.rent_single)}<span className="text-xs text-gray-400 font-medium">/wk</span>
                          </span>
                        </div>
                        {acc.rent_couple != null && (
                          <div className="flex items-baseline justify-between gap-2 mt-3 pt-3 border-t border-[#1F5A8B]/15">
                            <span className="text-[10px] font-bold uppercase tracking-[0.15em] text-[#1F5A8B]">Couple</span>
                            <span className="text-2xl font-extrabold text-[#282728] leading-none">
                              {money(acc.rent_couple)}<span className="text-xs text-gray-400 font-medium">/wk</span>
                            </span>
                          </div>
                        )}
                        <p className="text-[10px] text-gray-400 mt-3">{acc.bills_excluded ? 'Excludes bills' : 'Bills included'}</p>
                      </>
                    )}
                  </div>

                  <p className="text-[11px] font-bold mb-3">{acc.rental_mode === 'whole_property' ? 'Property details' : 'Room details'}</p>
                  <div className="space-y-2.5 text-[11px]">
                    {acc.rental_mode === 'whole_property' ? (
                      <>
                        <div className="flex justify-between"><span className="text-gray-500">Bedrooms</span><span className="font-semibold text-gray-400">{acc.bedrooms ?? '—'}</span></div>
                        <div className="flex justify-between"><span className="text-gray-500">Bathrooms</span><span className="font-semibold text-gray-400">{acc.bathrooms ?? '—'}</span></div>
                        <div className="flex justify-between"><span className="text-gray-500">Rental type</span><span className="font-semibold text-gray-400">Whole property</span></div>
                      </>
                    ) : (
                      <>
                        <div className="flex justify-between"><span className="text-gray-500">Room type</span><span className="font-semibold text-gray-400 capitalize">{acc.room_type}</span></div>
                        <div className="flex justify-between"><span className="text-gray-500">Bed</span><span className="font-semibold text-gray-400 capitalize">{acc.bed_type} mattress</span></div>
                        <div className="flex justify-between"><span className="text-gray-500">Wardrobe</span><span className="font-semibold text-gray-400">{acc.has_wardrobe ? 'Yes' : 'No'}</span></div>
                        <div className="flex justify-between"><span className="text-gray-500">Bathroom</span><span className="font-semibold text-gray-400 capitalize">{acc.bathroom_type}</span></div>
                      </>
                    )}
                  </div>
                </div>

                {/* Column 4: CTA */}
                <div className="flex flex-col justify-between h-full py-4 lg:pl-6">
                  <div className="space-y-3">
                    <p className="text-[10px] font-bold tracking-[0.2em] text-gray-400 uppercase">What's included</p>
                    {splitIncludes(acc.includes).length > 0 ? (
                      <ul className="space-y-2">
                        {splitIncludes(acc.includes).map((item, i) => (
                          <li key={i} className="flex items-start gap-2 text-[12px] text-gray-700 leading-snug">
                            <svg className="w-4 h-4 text-[#1F5A8B] shrink-0 mt-0.5" fill="none" stroke="currentColor" strokeWidth="2.5" viewBox="0 0 24 24">
                              <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                            <span>{item}</span>
                          </li>
                        ))}
                      </ul>
                    ) : (
                      <p className="text-[11px] text-gray-400 leading-relaxed">Contact us for the full list of inclusions.</p>
                    )}
                  </div>
                  <a href={`/accommodation/${acc.slug}`} className="w-full mt-8 py-4 bg-[#1F5A8B] text-white rounded-full text-sm font-bold hover:bg-[#184A73] transition-colors shadow-lg shadow-[#1F5A8B]/20 text-center block">
                    View details
                  </a>
                </div>

              </div>
            ))
          )}
        </div>
      </section>

      {/* Concern CTA */}
      <section className="border-t border-gray-100 bg-white">
        <div className="mx-auto max-w-3xl px-4 py-16 text-center sm:px-6">
          <span className="text-[11px] font-bold uppercase tracking-[0.15em] text-[#1F5A8B]">Already a tenant?</span>
          <h2 className="mt-3 text-2xl font-bold text-gray-900 sm:text-3xl">Have a concern about your place?</h2>
          <p className="mx-auto mt-3 max-w-xl text-sm leading-relaxed text-gray-500">
            Living in one of our properties and something's not right? Let us know — just enter your name and email, pick your
            property, and we'll make sure the right person looks into it.
          </p>
          <a
            href="/accommodation/concern"
            className="mt-6 inline-flex items-center justify-center rounded-full bg-[#1F5A8B] px-8 py-3.5 text-sm font-bold text-white shadow-lg shadow-[#1F5A8B]/20 transition-colors hover:bg-[#184A73]"
          >
            Submit a concern
          </a>
        </div>
      </section>

      <Footer />
    </div>
  );
};

export default Accommodation;
