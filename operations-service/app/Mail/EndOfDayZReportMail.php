<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EndOfDayZReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public array $reportData
    ) {}

    public function envelope(): Envelope
    {
        $code = $this->reportData['z_report_code'] ?? 'Z-REPORT';
        $date = $this->reportData['date'] ?? date('Y-m-d');
        $outlet = $this->reportData['outlet_name'] ?? 'Main Store';
        $gross = number_format((float) ($this->reportData['gross_sales'] ?? 0), 2);

        return new Envelope(
            subject: "📊 End-of-Day (EOD) Z-Report: {$code} - {$outlet} (\${$gross} on {$date})",
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->buildHtml(),
        );
    }

    protected function buildHtml(): string
    {
        $d = $this->reportData;
        $grossSales = number_format((float) ($d['gross_sales'] ?? 0), 2);
        $netSales = number_format((float) ($d['net_sales'] ?? 0), 2);
        $taxTotal = number_format((float) ($d['tax_total'] ?? 0), 2);
        $discountTotal = number_format((float) ($d['discount_total'] ?? 0), 2);
        $refundTotal = number_format((float) ($d['refund_total'] ?? 0), 2);

        $cashSales = number_format((float) ($d['payment_breakdown']['cash'] ?? 0), 2);
        $khqrSales = number_format((float) ($d['payment_breakdown']['khqr'] ?? 0), 2);
        $cardSales = number_format((float) ($d['payment_breakdown']['card'] ?? 0), 2);

        $openingFloat = number_format((float) ($d['cash_drawer']['opening_float'] ?? 0), 2);
        $expectedCash = number_format((float) ($d['cash_drawer']['expected_cash'] ?? 0), 2);
        $countedCash = number_format((float) ($d['cash_drawer']['counted_cash'] ?? 0), 2);
        $cashVariance = (float) ($d['cash_drawer']['cash_variance'] ?? 0);
        $varianceFormatted = ($cashVariance >= 0 ? '+' : '-') . '$' . number_format(abs($cashVariance), 2);
        $varianceColor = abs($cashVariance) < 0.01 ? '#10B981' : ($cashVariance < 0 ? '#EF4444' : '#F59E0B');

        $registersClosed = $d['registers_closed'] ?? 0;
        $totalOrders = $d['total_orders'] ?? 0;

        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='utf-8'>
            <title>End-of-Day Z-Report Financial Summary</title>
        </head>
        <body style='font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, sans-serif; background-color: #F8FAFC; margin: 0; padding: 24px; color: #1E293B;'>
            <table width='100%' cellpadding='0' cellspacing='0' style='max-width: 650px; margin: 0 auto; background: #FFFFFF; border-radius: 12px; border: 1px solid #E2E8F0; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);'>
                <!-- Header -->
                <tr>
                    <td style='background: linear-gradient(135deg, #0F172A 0%, #1E293B 100%); padding: 24px; border-bottom: 3px solid #3B82F6;'>
                        <table width='100%'>
                            <tr>
                                <td>
                                    <h1 style='color: #FFFFFF; margin: 0; font-size: 20px; font-weight: 700; letter-spacing: -0.02em;'>
                                        📊 End-of-Day (EOD) Z-Report
                                    </h1>
                                    <p style='color: #94A3B8; margin: 6px 0 0 0; font-size: 13px;'>
                                        Official Store Financial Summary & Shift Settlement
                                    </p>
                                </td>
                                <td style='text-align: right;'>
                                    <span style='background: #1E3A8A; color: #93C5FD; padding: 6px 12px; border-radius: 6px; font-family: monospace; font-size: 13px; font-weight: 600;'>
                                        {$d['z_report_code']}
                                    </span>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <!-- Meta Row -->
                <tr>
                    <td style='padding: 16px 24px; background: #F8FAFC; border-bottom: 1px solid #E2E8F0;'>
                        <table width='100%' style='font-size: 13px; color: #64748B;'>
                            <tr>
                                <td><strong>Store Outlet:</strong> {$d['outlet_name']}</td>
                                <td><strong>Date:</strong> {$d['date']}</td>
                                <td style='text-align: right;'><strong>Total Orders:</strong> {$totalOrders}</td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <!-- Financial Highlights Cards -->
                <tr>
                    <td style='padding: 24px 24px 12px 24px;'>
                        <table width='100%' cellpadding='0' cellspacing='0'>
                            <tr>
                                <td width='32%' style='background: #EFF6FF; border: 1px solid #BFDBFE; border-radius: 8px; padding: 14px; text-align: center;'>
                                    <div style='font-size: 11px; font-weight: 600; color: #1E40AF; text-transform: uppercase;'>Gross Sales</div>
                                    <div style='font-size: 20px; font-weight: 700; color: #1E3A8A; margin-top: 4px;'>\${$grossSales}</div>
                                </td>
                                <td width='2%'></td>
                                <td width='32%' style='background: #ECFDF5; border: 1px solid #A7F3D0; border-radius: 8px; padding: 14px; text-align: center;'>
                                    <div style='font-size: 11px; font-weight: 600; color: #065F46; text-transform: uppercase;'>Net Sales</div>
                                    <div style='font-size: 20px; font-weight: 700; color: #047857; margin-top: 4px;'>\${$netSales}</div>
                                </td>
                                <td width='2%'></td>
                                <td width='32%' style='background: #FEF3C7; border: 1px solid #FDE68A; border-radius: 8px; padding: 14px; text-align: center;'>
                                    <div style='font-size: 11px; font-weight: 600; color: #92400E; text-transform: uppercase;'>Total VAT / Tax</div>
                                    <div style='font-size: 20px; font-weight: 700; color: #B45309; margin-top: 4px;'>\${$taxTotal}</div>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <!-- Tender Breakdown -->
                <tr>
                    <td style='padding: 12px 24px;'>
                        <h3 style='margin: 0 0 12px 0; font-size: 14px; color: #0F172A; text-transform: uppercase; letter-spacing: 0.05em;'>
                            💳 Collections by Payment Tender
                        </h3>
                        <table width='100%' cellpadding='8' cellspacing='0' style='font-size: 13px; border-collapse: collapse; border: 1px solid #E2E8F0; border-radius: 6px;'>
                            <tr style='background: #F8FAFC; border-bottom: 1px solid #E2E8F0;'>
                                <th style='text-align: left; color: #64748B;'>Tender Method</th>
                                <th style='text-align: right; color: #64748B;'>Settled Amount</th>
                            </tr>
                            <tr style='border-bottom: 1px solid #F1F5F9;'>
                                <td><strong>NBC Bakong KHQR</strong></td>
                                <td style='text-align: right; font-weight: 600; color: #0F172A;'>\${$khqrSales}</td>
                            </tr>
                            <tr style='border-bottom: 1px solid #F1F5F9;'>
                                <td><strong>Physical Cash</strong></td>
                                <td style='text-align: right; font-weight: 600; color: #0F172A;'>\${$cashSales}</td>
                            </tr>
                            <tr style='border-bottom: 1px solid #F1F5F9;'>
                                <td><strong>Credit / Debit Card</strong></td>
                                <td style='text-align: right; font-weight: 600; color: #0F172A;'>\${$cardSales}</td>
                            </tr>
                            <tr style='background: #F8FAFC; font-weight: 700;'>
                                <td>Total Reconciled Collections</td>
                                <td style='text-align: right; color: #1E40AF;'>\${$grossSales}</td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <!-- Cash Drawer & Till Audit -->
                <tr>
                    <td style='padding: 12px 24px 24px 24px;'>
                        <h3 style='margin: 0 0 12px 0; font-size: 14px; color: #0F172A; text-transform: uppercase; letter-spacing: 0.05em;'>
                            🔒 Cash Drawer & Register Reconciliation
                        </h3>
                        <table width='100%' cellpadding='8' cellspacing='0' style='font-size: 13px; border-collapse: collapse; border: 1px solid #E2E8F0; border-radius: 6px;'>
                            <tr style='border-bottom: 1px solid #F1F5F9;'>
                                <td style='color: #64748B;'>Registers Closed (EOD):</td>
                                <td style='text-align: right; font-weight: 600; color: #0F172A;'>{$registersClosed} Shift(s)</td>
                            </tr>
                            <tr style='border-bottom: 1px solid #F1F5F9;'>
                                <td style='color: #64748B;'>Opening Till Float:</td>
                                <td style='text-align: right; font-weight: 600; color: #0F172A;'>\${$openingFloat}</td>
                            </tr>
                            <tr style='border-bottom: 1px solid #F1F5F9;'>
                                <td style='color: #64748B;'>Expected Drawer Cash:</td>
                                <td style='text-align: right; font-weight: 600; color: #0F172A;'>\${$expectedCash}</td>
                            </tr>
                            <tr style='border-bottom: 1px solid #F1F5F9;'>
                                <td style='color: #64748B;'>Actual Counted Cash:</td>
                                <td style='text-align: right; font-weight: 600; color: #0F172A;'>\${$countedCash}</td>
                            </tr>
                            <tr style='background: #F8FAFC; font-weight: 700;'>
                                <td>Cash Float Discrepancy (Variance)</td>
                                <td style='text-align: right; color: {$varianceColor};'>{$varianceFormatted}</td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <!-- Footer -->
                <tr>
                    <td style='background: #F8FAFC; padding: 18px 24px; border-top: 1px solid #E2E8F0; text-align: center; color: #94A3B8; font-size: 11px;'>
                        <p style='margin: 0;'>CodeBridges Enterprise POS • Automated Nightly End-of-Day Spooler</p>
                        <p style='margin: 4px 0 0 0;'>Generated at {$d['generated_at']} • System Audit ID: {$d['z_report_code']}</p>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        ";
    }
}

