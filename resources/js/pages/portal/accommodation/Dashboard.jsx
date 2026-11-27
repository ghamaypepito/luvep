import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import { InsightsHeader, PortfolioSummary, OccupancyPanel, ViewingCalendar, InventoryTable, dateLabel } from '../../../components/accommodation/Insights';

export default function AccommodationDashboard({ propertyStats = {}, applicationStats = {}, recentProperties = [], recentApplications = [], inventoryStats = null, inventoryError = null, insights = null, viewingTotal = null }) {
    const [roomFilter, setRoomFilter] = useState('all');
    return <div className="accommodation-insights">
        <Head title="Accommodation Dashboard"/>
        <InsightsHeader asOf={insights?.as_of}/>
        {inventoryError && <p role="alert" className="ai-error">{inventoryError}</p>}
        <PortfolioSummary stats={inventoryStats} dashboardCounts={{ leads: applicationStats.total, viewings: viewingTotal }} onFilter={setRoomFilter}/>
        <div className="ai-main-grid"><OccupancyPanel stats={inventoryStats}/><ViewingCalendar key={insights?.calendar.month} insights={insights} onMonthChange={month => router.get('/portal/accommodation/dashboard', {month}, {preserveScroll:true})}/></div>
        <InventoryTable stats={inventoryStats} filter={roomFilter} onFilter={setRoomFilter}/>
        <div className="ai-bottom-grid"><section className="ai-panel">
            <div className="ai-panel-heading"><div><h2>Recent leads</h2><p>{applicationStats.total ?? '—'} total · {applicationStats.new ?? '—'} awaiting review</p></div><Link className="ai-icon-link" href="/portal/accommodation/applications" aria-label="View all Accommodation leads"><ArrowUpRight size={20}/></Link></div>
            <ul className="ai-recent-list">{recentApplications.map(application => <li key={application.id}><div><Link href={`/portal/accommodation/applications/${application.id}`}>{application.full_legal_name}</Link><span>{application.room_type_interest || 'Room preference not recorded'}</span></div><div><span className="ai-status">{(application.status || 'new').replaceAll('_', ' ')}</span><small>{dateLabel(application.created_at)}</small></div></li>)}</ul>
            {!recentApplications.length && <p className="ai-empty">{inventoryError ? 'Lead figures could not be loaded.' : 'No leads yet. New expressions of interest will appear here.'}</p>}
        </section><section className="ai-panel">
            <div className="ai-panel-heading"><div><h2>Property listings</h2><p>{propertyStats.available ?? '—'} available · {propertyStats.unavailable ?? '—'} hidden</p></div><Link className="ai-icon-link" href="/portal/accommodation/properties" aria-label="View all properties"><ArrowUpRight size={20}/></Link></div>
            <ul className="ai-recent-list">{recentProperties.map(property => <li key={property.id}><div><Link href={`/portal/accommodation/properties/${property.id}/edit`}>{property.name}</Link><span>{property.suburb || property.location || 'Location not recorded'}</span></div><span className="ai-status">{property.status}</span></li>)}</ul>
            {!recentProperties.length && <p className="ai-empty">{inventoryError ? 'Property listings could not be loaded.' : 'No properties yet. Add a property to begin.'}</p>}
            <p className="ai-footnote">Public listing publication is separate from verified room vacancy.</p>
        </section></div>
    </div>;
}
