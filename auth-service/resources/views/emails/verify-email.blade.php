<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Verify Your Email Address - CodeBridges</title>
    <style>
        /* Base styles */
        body {
            margin: 0;
            padding: 0;
            background-color: #f1f5f9;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            color: #1e293b;
            -webkit-font-smoothing: antialiased;
        }
        table {
            border-collapse: collapse;
        }
        img {
            border: 0;
            outline: none;
            text-decoration: none;
        }
        /* Mobile layout */
        @media only screen and (max-width: 600px) {
            .wrapper-table {
                width: 100% !important;
                padding: 12px !important;
            }
            .content-card {
                padding: 24px 18px !important;
            }
            .otp-box {
                font-size: 24px !important;
                letter-spacing: 5px !important;
                padding: 12px 16px !important;
            }
            .btn-cta {
                display: block !important;
                width: 100% !important;
                box-sizing: border-box !important;
                text-align: center !important;
            }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #1e293b;">

    <!-- Wrapper Table -->
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f1f5f9; width: 100%; padding: 40px 10px;">
        <tr>
            <td align="center">
                
                <!-- Main Container (max-width: 580px) -->
                <table role="presentation" class="wrapper-table" width="580" cellpadding="0" cellspacing="0" style="max-width: 580px; width: 100%; margin: 0 auto;">
                    
                    <!-- Brand Header -->
                    <tr>
                        <td align="center" style="padding-bottom: 24px;">
                            <div style="display: inline-flex; align-items: center; justify-content: center; gap: 8px;">
                                <div style="background: linear-gradient(135deg, #2563eb, #7c3aed); width: 40px; height: 40px; border-radius: 10px; display: inline-block; vertical-align: middle; text-align: center; line-height: 40px;">
                                    <span style="color: #ffffff; font-size: 20px; font-weight: 800; font-family: monospace;">CB</span>
                                </div>
                                <span style="font-size: 22px; font-weight: 800; color: #0f172a; letter-spacing: -0.5px; vertical-align: middle; margin-left: 8px;">Code<span style="color: #2563eb;">Bridges</span></span>
                            </div>
                            <div style="font-size: 13px; color: #64748b; font-weight: 500; margin-top: 4px;">SmartPOS & Unified Cloud Services</div>
                        </td>
                    </tr>

                    <!-- Main Card -->
                    <tr>
                        <td class="content-card" style="background-color: #ffffff; border-radius: 16px; padding: 36px 32px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05); border: 1px solid #e2e8f0;">
                            
                            <!-- Email Title -->
                            <h1 style="margin: 0 0 16px 0; font-size: 22px; font-weight: 700; color: #0f172a; line-height: 1.3;">
                                Verify Your Email Address
                            </h1>

                            <!-- Greeting -->
                            <p style="margin: 0 0 16px 0; font-size: 15px; color: #334155; line-height: 1.6;">
                                Hello <strong>{{ $user->name ?? 'there' }}</strong>,
                            </p>

                            <p style="margin: 0 0 24px 0; font-size: 15px; color: #475569; line-height: 1.6;">
                                Thank you for creating an account with <strong>CodeBridges</strong>. To finish setting up your account and ensure your account security, please verify your email address.
                            </p>

                            <!-- Primary CTA Button (Send link to email) -->
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 28px 0;">
                                <tr>
                                    <td align="center">
                                        <a href="{{ $verificationUrl }}" target="_blank" class="btn-cta" style="background: linear-gradient(135deg, #2563eb, #1d4ed8); color: #ffffff; text-decoration: none; font-size: 15px; font-weight: 600; padding: 14px 32px; border-radius: 8px; display: inline-block; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25); text-align: center;">
                                            Verify Email Address &rarr;
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <!-- Alternative OTP Section -->
                            @if(!empty($code))
                            <div style="background-color: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 10px; padding: 18px; margin: 24px 0; text-align: center;">
                                <div style="font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; margin-bottom: 8px;">
                                    Or enter this 6-digit verification code:
                                </div>
                                <div class="otp-box" style="font-family: 'SF Mono', Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace; font-size: 28px; font-weight: 700; letter-spacing: 8px; color: #0f172a; padding: 6px 0;">
                                    {{ $code }}
                                </div>
                                <div style="font-size: 12px; color: #94a3b8; margin-top: 4px;">
                                    Valid for 15 minutes
                                </div>
                            </div>
                            @endif

                            <!-- Expiry Alert -->
                            <div style="background-color: #eff6ff; border-left: 4px solid #3b82f6; border-radius: 4px; padding: 12px 14px; margin: 24px 0;">
                                <p style="margin: 0; font-size: 13px; color: #1e40af; line-height: 1.5;">
                                    ⏰ <strong>Notice:</strong> This verification link is valid for <strong>{{ $expireMinutes ?? 60 }} minutes</strong>. For your security, once this link expires you will need to request a new one.
                                </p>
                            </div>

                            <!-- Fallback Link -->
                            <div style="border-top: 1px solid #f1f5f9; padding-top: 20px; margin-top: 24px;">
                                <p style="margin: 0 0 8px 0; font-size: 12px; color: #64748b; line-height: 1.5;">
                                    If the button above doesn't work, copy and paste this verification URL into your browser:
                                </p>
                                <p style="margin: 0; font-size: 12px; color: #2563eb; word-break: break-all; line-height: 1.4; background-color: #f8fafc; padding: 10px; border-radius: 6px; border: 1px solid #e2e8f0;">
                                    <a href="{{ $verificationUrl }}" target="_blank" style="color: #2563eb; text-decoration: underline;">
                                        {{ $verificationUrl }}
                                    </a>
                                </p>
                            </div>

                            <!-- Security Note -->
                            <p style="margin: 24px 0 0 0; font-size: 13px; color: #94a3b8; line-height: 1.5;">
                                If you did not create an account or request this verification, please disregard this email. Your account remains secure.
                            </p>

                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td align="center" style="padding-top: 24px;">
                            <p style="margin: 0 0 6px 0; font-size: 12px; color: #94a3b8;">
                                &copy; {{ date('Y') }} CodeBridges Technologies. All rights reserved.
                            </p>
                            <p style="margin: 0; font-size: 11px; color: #cbd5e1;">
                                This is an automated transactional security message. Please do not reply directly to this email.
                            </p>
                        </td>
                    </tr>

                </table>

            </td>
        </tr>
    </table>

</body>
</html>

