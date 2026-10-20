<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="light"><title>CeleOne verification code</title></head>
<body style="margin:0;padding:0;background:#edf5f6;color:#15343b;font-family:Arial,Helvetica,sans-serif;">
@php($recovery = $purpose === 'recovery')
<div style="display:none;max-height:0;overflow:hidden;opacity:0;mso-hide:all;">Your CeleOne verification code expires in {{ $minutes }} minutes.</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#edf5f6;"><tr><td align="center" style="padding:36px 16px;">
<table role="presentation" width="560" cellspacing="0" cellpadding="0" style="width:100%;max-width:560px;background:#ffffff;border:1px solid #d9e8eb;border-radius:20px;overflow:hidden;">
<tr><td style="background:#073b43;padding:30px 32px;color:#ffffff;">
<div style="font-size:27px;font-weight:800;letter-spacing:-1px;">Cèlè<span style="color:#7be2d1;">One</span></div>
<div style="font-size:11px;letter-spacing:2px;color:#bde1e1;margin-top:9px;">ACCOUNT SECURITY</div>
</td></tr>
<tr><td style="padding:32px 32px 12px;">
<div style="display:inline-block;background:#e9f8f4;color:#137966;padding:7px 11px;border-radius:20px;font-size:11px;font-weight:bold;letter-spacing:1px;">{{ $recovery ? 'PASSWORD RECOVERY' : 'ACCOUNT UPGRADE' }}</div>
<h1 style="font-size:27px;line-height:35px;letter-spacing:-0.6px;margin:20px 0 12px;color:#15343b;">{{ $recovery ? "Let's get you back in." : 'A safer start for your account.' }}</h1>
<p style="font-size:15px;line-height:25px;color:#536c72;margin:0 0 22px;">{{ $recovery ? 'Use the verification code below to reset your CeleOne password and regain access to your account.' : 'Use this code to confirm your email and create your password for the upgraded CeleOne service.' }}</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center" style="background:#f1faf8;border:1px solid #c9e7df;border-radius:14px;padding:23px 12px;">
<div style="font-size:11px;font-weight:bold;letter-spacing:2px;color:#47776e;">YOUR VERIFICATION CODE</div>
<div style="font-family:Consolas,Monaco,monospace;font-size:36px;line-height:52px;font-weight:bold;letter-spacing:7px;color:#073b43;margin-top:8px;">{{ $code }}</div>
<div style="font-size:12px;color:#536c72;margin-top:7px;">Expires in <strong>{{ $minutes }} minutes</strong></div>
</td></tr></table>
<p style="font-size:14px;line-height:23px;color:#536c72;margin:22px 0;">Return to the CeleOne app and enter this code. Keep it private - CeleOne will never ask you to share it in a message or phone call.</p>
</td></tr>
<tr><td style="padding:0 32px 30px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td style="border-top:1px solid #e3eeee;padding-top:20px;font-size:12px;line-height:20px;color:#6e8388;"><strong style="color:#405e66;">Didn't request this?</strong><br>You can ignore this email. Your password will remain unchanged unless the verification process is completed.</td></tr></table></td></tr>
</table>
<div style="font-size:12px;line-height:20px;color:#70868b;margin-top:20px;">Cèlè One - Your community, connected.<br>This is an automated security email.</div>
</td></tr></table>
</body></html>
