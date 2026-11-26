import { useState } from 'react';
import { Head, router } from "@inertiajs/react";
import WeeklyReport, { WeekButtons } from "@/components/WeeklyReport";
import { InsightsHeader, PortfolioSummary, TaskActivity, ViewingCalendar, InventoryTable } from '../../../components/accommodation/Insights';

export default function Reports({ preset, weeklyReport, inventoryStats = null, insights = null, inventoryError = null }) {
    const [roomFilter, setRoomFilter] = useState('all');
    return (
        <div className="accommodation-insights">
            <Head title="Accommodation reports" />
            <InsightsHeader reports asOf={insights?.as_of}>
                <WeekButtons preset={preset} onChange={selected => router.get('/portal/accommodation/reports', {preset:selected, month:insights?.calendar.month}, {preserveScroll:true})}/>
            </InsightsHeader>
            {inventoryError && <p role="alert" className="ai-error">{inventoryError}</p>}
            <div className="ai-main-grid"><TaskActivity insights={insights}/><ViewingCalendar key={insights?.calendar.month} insights={insights} onMonthChange={month => router.get('/portal/accommodation/reports', {preset, month}, {preserveScroll:true})}/></div>
            <WeeklyReport report={weeklyReport} saveUrl="/portal/accommodation/reports/note" />
            <section aria-label="Current portfolio snapshot" className="ai-current-snapshot"><h2>Current portfolio</h2><p>These figures describe current records. The weekly selector applies to team activity and notes above.</p></section>
            <PortfolioSummary stats={inventoryStats} onFilter={setRoomFilter}/>
            <InventoryTable stats={inventoryStats} filter={roomFilter} onFilter={setRoomFilter}/>
        </div>
    );
}
