<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ShiftVarianceAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public array $alertData
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $type = strtoupper($this->alertData['variance_type'] ?? 'VARIANCE');
        $amount = number_format(abs($this->alertData['cash_variance'] ?? 0), 2);
        return new Envelope(
            subject: "🚨 Cash Drawer Variance Alert: Shift #{$this->alertData['shift_id']} ({$type} of \${$amount})",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            htmlString: $this->buildHtml(),
        );
    }

    /**
     * Build clean responsive HTML email template.
     */
    protected function buildHtml(): string
    {
        $d = $this->alertData;
        $varianceFormatted = ($d['cash_variance'] >= 0 ? '+' : '-') . '$' . number_format(abs($d['cash_variance']), 2);
        $badgeColor = $d['variance_type'] === 'shortage' ? '#EF4444' : '#F59E0B';

        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='utf-8'>
            <title>Shift Cash Variance Alert</title>
        </head>
        <body style='font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, sans-serif; background-color: #F8FAFC; margin: 0; padding: 24px; color: #1E293B;'>
            <table width='100%' cellpadding='0' cellspacing='0' style='max-width: 600px; margin: 0 auto; background: #FFFFFF; border-radius: 12px; border: 1px solid #E2E8F0; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);'>
                <tr>
                    <td style='background: #0F172A; padding: 20px 24px;'>
                        <h2 style='color: #FFFFFF; margin: 0; font-size: 18px; font-weight: 700; letter-spacing: -0.02em;'>
                            🚨 Cash Drawer Float Variance Notification
                        </h2>
                        <p style='color: #94A3B8; margin: 4px 0 0 0; font-size: 12px;'>CodeBridges Enterprise POS Till Auditing</p>
                    </td>
                </tr>
                <tr>
                    <td style='padding: 24px;'>
                        <div style='background: #FEF2F2; border-left: 4px solid {$badgeColor}; padding: 14px 16px; border-radius: 6px; margin-bottom: 20px;'>
                            <strong style='color: #991B1B; font-size: 14px;'>Threshold Discrepancy Detected:</strong>
                            <p style='margin: 4px 0 0 0; color: #7F1D1D; font-size: 13px;'>
                                Cash drawer variance of <strong style='font-size: 15px;'>{$varianceFormatted}</strong> exceeds permitted tolerance of <strong>\${$d['tolerance_threshold']}</strong>.
                            </p>
                        </div>
                        <table width='100%' cellpadding='8' cellspacing='0' style='font-size: 13px; border-collapse: collapse;'>
                            <tr style='border-bottom: 1px solid #F1F5F9;'>
                                <td style='color: #64748B; width: 40%;'>Shift ID:</td>
                                <td style='font-weight: 600; color: #0F172A;'>#{$d['shift_id']}</td>
                            </tr>
                            <tr style='border-bottom: 1px solid #F1F5F9;'>
                                <td style='color: #64748B;'>Cashier:</td>
                                <td style='font-weight: 600; color: #0F172A;'>{$d['cashier_name']} (ID: {$d['cashier_id']})</td>
                            </tr>
                            <tr style='border-bottom: 1px solid #F1F5F9;'>
                                <td style='color: #64748B;'>Outlet / Terminal:</td>
                                <td style='font-weight: 600; color: #0F172A;'>{$d['outlet_name']}</td>
                            </tr>
                            <tr style='border-bottom: 1px solid #F1F5F9;'>
                                <td style='color: #64748B;'>Expected Cash:</td>
                                <td style='font-weight: 600; color: #0F172A;'>\${$d['expected_cash']}</td>
                            </tr>
                            <tr style='border-bottom: 1px solid #F1F5F9;'>
                                <td style='color: #64748B;'>Counted Cash:</td>
                                <td style='font-weight: 600; color: #0F172A;'>\${$d['counted_cash']}</td>
                            </tr>
                            <tr style='border-bottom: 1px solid #F1F5F9;'>
                                <td style='color: #64748B;'>Discrepancy (Variance):</td>
                                <td style='font-weight: 700; color: {$badgeColor};'>{$varianceFormatted} (" . strtoupper($d['variance_type']) . ")</td>
                            </tr>
                            <tr style='border-bottom: 1px solid #F1F5F9;'>
                                <td style='color: #64748B;'>Closing Note:</td>
                                <td style='font-style: italic; color: #334155;'>" . ($d['closing_note'] ?? 'No closing remarks provided') . "</td>
                            </tr>
                            <tr>
                                <td style='color: #64748B;'>Closed At:</td>
                                <td style='color: #334155;'>{$d['closed_at']}</td>
                            </tr>
                        </table>
                        <div style='margin-top: 24px; padding-top: 16px; border-top: 1px solid #E2E8F0; text-align: center; color: #94A3B8; font-size: 11px;'>
                            CodeBridges Automated Security Incident Service • Real-Time Till Audit
                        </div>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        ";
    }
}
