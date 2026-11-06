import { Head } from '@inertiajs/react';
import ConversationPanel from '../../components/ep-agent/ConversationPanel';
import '../../../css/ep-agent.css';
export default function EpWorkspace({ ep }) {
    return <main className="ep-shell"><Head title="Ep Agent" /><header className="ep-page-heading"><div><h1>Ep Agent</h1><p>Your own conversation. A little more room to get things done.</p></div><span className="ep-status">AI generation off</span></header><ConversationPanel portal={ep.portal} portals={ep.portals} context={ep.context} initialConversationId={ep.conversation_id} /></main>;
}
