// Education per-agent leads — reuses the sales AgentLeads screen. Resolved
// under portal/education/ so app.jsx wraps it in EducationLayout;
// SalesController::agentLeadsPage feeds portalBase="/portal/education".
//
// A thin wrapper (not a re-export) so this page gets its own layout binding,
// independent of the sales/admin copies that share the same view.
import AgentLeadsView from '@/pages/portal/sales/AgentLeads';

export default function EducationAgentLeads(props) {
    return <AgentLeadsView {...props} showProfileUpdates />;
}
