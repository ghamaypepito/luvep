<?php

namespace App\Jobs;

use App\Models\EmailCampaign;
use App\Models\Lead;
use App\Services\CommunicationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sends one bulk-email campaign: marks it sending, queues a personalised email
 * for each snapshot recipient (logged against the campaign), tallies
 * sent/failed/skipped, and marks the campaign complete. Each per-recipient email is itself
 * queued by CommunicationService, so this job just fans them out.
 */
class SendCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(public int $campaignId) {}

    public function handle(CommunicationService $comms): void
    {
        $campaign = EmailCampaign::find($this->campaignId);
        if (! $campaign || in_array($campaign->status, [EmailCampaign::STATUS_SENT, EmailCampaign::STATUS_SKIPPED, EmailCampaign::STATUS_CANCELED], true)) {
            return;
        }

        $campaign->update(['status' => EmailCampaign::STATUS_SENDING, 'started_at' => now()]);

        $sent = 0;
        $failed = 0;
        $skipped = 0;
        $isSms = $campaign->channel === EmailCampaign::CHANNEL_SMS;

        // Carry the source template's branding (banner/footer/sender) so bulk
        // emails match the template exactly, not the default shell.
        $template = $campaign->template;
        $banner = $template?->banner_image;
        $footer = $template?->footer_image;
        $fromEmail = $template?->from_email;
        $fromName = $template?->from_name;

        foreach (array_chunk($campaign->recipient_lead_ids ?? [], 200) as $chunk) {
            $leads = Lead::whereIn('id', $chunk)
                ->when($campaign->new_leads_only, fn ($query) => $query->inNewLeads())
                ->get();
            if ($campaign->new_leads_only) {
                foreach (array_diff($chunk, $leads->modelKeys()) as $leadId) {
                    $campaign->logs()->firstOrCreate(
                        ['recipient_type' => 'lead', 'recipient_id' => $leadId, 'status' => 'skipped'],
                        [
                            'channel' => $campaign->channel,
                            'recipient_address' => '',
                            'subject' => $campaign->subject,
                            'error_message' => 'Lead was no longer in New Leads when the campaign ran.',
                            'triggered_by_user_id' => $campaign->created_by,
                        ],
                    );
                    $skipped++;
                }
            }
            foreach ($leads as $lead) {
                $ok = $isSms
                    ? $comms->sendCampaignSms($lead, $campaign->body, $campaign->id)
                    : $comms->sendCampaignEmail($lead, $campaign->subject, $campaign->body, $campaign->id, [], $banner, $footer, $fromEmail, $fromName);
                $ok ? $sent++ : $failed++;
            }
        }

        $status = match (true) {
            $sent === 0 && $failed > 0 => EmailCampaign::STATUS_FAILED,
            $sent === 0 && $skipped > 0 => EmailCampaign::STATUS_SKIPPED,
            default => EmailCampaign::STATUS_SENT,
        };

        $campaign->update([
            'status' => $status,
            'sent_count' => $sent,
            'failed_count' => $failed,
            'skipped_count' => $skipped,
            'completed_at' => now(),
        ]);

        Log::info('Bulk campaign dispatched', ['campaign_id' => $campaign->id, 'sent' => $sent, 'failed' => $failed, 'skipped' => $skipped]);
    }
}
