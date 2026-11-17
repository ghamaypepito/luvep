// The recorded referee is a name, not a portal account or an access grant.
export function studentReferralLabel(student) {
    const agent = String(student.agent_name || "").trim();
    const referee = String(student.referral || "").trim();
    if (!agent) return referee;
    if (!referee || agent.toLocaleLowerCase() === referee.toLocaleLowerCase()) return agent;
    return `${agent} · Referee: ${referee}`;
}
