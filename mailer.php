<?php
/**
 * Aroseglink Services Limited - Universal Form Mailer
 * Handles form submissions from Contact, Home Appointment, Study Abroad, and Newsletter forms.
 * Delivers submissions to: info@aroseglinkserviceslimited.com
 */

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

// Target recipient
$recipient_email = 'info@aroseglinkserviceslimited.com';

// Read JSON input or $_POST
$raw_input = file_get_contents('php://input');
$data = json_decode($raw_input, true);
if (!$data || empty($data)) {
    $data = $_POST;
}

// Extract and sanitize fields
function sanitize($val) {
    if (is_array($val)) return implode(', ', array_map('sanitize', $val));
    return htmlspecialchars(trim((string)$val), ENT_QUOTES, 'UTF-8');
}

$form_type = sanitize($data['form_type'] ?? $data['_form_type'] ?? 'General Inquiry');
$name = sanitize($data['name'] ?? $data['FullName'] ?? $data['fullname'] ?? 'Valued Client');
$email = filter_var(trim($data['email'] ?? $data['Email'] ?? ''), FILTER_VALIDATE_EMAIL);
$phone = sanitize($data['phone'] ?? $data['Phone'] ?? $data['number'] ?? $data['Number'] ?? 'Not provided');
$subject = sanitize($data['subject'] ?? $data['_subject'] ?? "New Submission: $form_type - Aroseglink Website");
$message = sanitize($data['message'] ?? $data['Message'] ?? 'No message entered');
$destination = sanitize($data['destination'] ?? $data['Destination'] ?? '');
$level = sanitize($data['level'] ?? $data['Level'] ?? '');
$intake = sanitize($data['intake'] ?? $data['Intake'] ?? '');

// Validation
if (!$email) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Please provide a valid email address.']);
    exit;
}

// Build human-readable HTML email
$date_time = date('Y-m-d H:i:s T');
$ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';

$email_body = '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: "Segoe UI", Helvetica, Arial, sans-serif; background-color: #f6f8fb; margin: 0; padding: 20px; color: #333333; }
        .container { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.06); border: 1px solid #eaeaea; }
        .header { background: #E51A24; color: #ffffff; padding: 25px 30px; }
        .header h1 { margin: 0; font-size: 20px; font-weight: 700; letter-spacing: 0.5px; }
        .header p { margin: 6px 0 0; font-size: 13px; opacity: 0.9; }
        .content { padding: 30px; }
        .info-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .info-table th, .info-table td { padding: 12px 14px; text-align: left; border-bottom: 1px solid #f0f0f0; font-size: 14px; vertical-align: top; }
        .info-table th { background: #fbfbfb; color: #666; width: 35%; font-weight: 600; }
        .info-table td { color: #222; }
        .message-box { background: #fdf2f2; border-left: 4px solid #E51A24; padding: 15px; margin-top: 20px; border-radius: 4px; font-size: 14px; line-height: 1.6; color: #222; }
        .footer { background: #fafafa; padding: 15px 30px; font-size: 12px; color: #888; text-align: center; border-top: 1px solid #f0f0f0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Aroseglink Services Limited</h1>
            <p>' . $form_type . ' Notification</p>
        </div>
        <div class="content">
            <h2 style="font-size: 16px; margin-top: 0; color: #111;">Submission Details:</h2>
            <table class="info-table">
                <tr><th>Full Name</th><td><strong>' . $name . '</strong></td></tr>
                <tr><th>Email Address</th><td><a href="mailto:' . $email . '" style="color: #E51A24;">' . $email . '</a></td></tr>
                <tr><th>Phone / WhatsApp</th><td>' . $phone . '</td></tr>
                <tr><th>Form Type</th><td>' . $form_type . '</td></tr>';

if (!empty($destination)) {
    $email_body .= '<tr><th>Preferred Destination</th><td>' . $destination . '</td></tr>';
}
if (!empty($level)) {
    $email_body .= '<tr><th>Level of Study</th><td>' . $level . '</td></tr>';
}
if (!empty($intake)) {
    $email_body .= '<tr><th>Intake</th><td>' . $intake . '</td></tr>';
}

$email_body .= '
                <tr><th>Submission Time</th><td>' . $date_time . '</td></tr>
                <tr><th>Client IP</th><td>' . $ip_address . '</td></tr>
            </table>

            <h3 style="font-size: 14px; margin-top: 25px; margin-bottom: 8px; color: #333;">Message / Request Details:</h3>
            <div class="message-box">' . nl2br($message) . '</div>
        </div>
        <div class="footer">
            <p>This message was sent from the Aroseglink Services Limited website form.</p>
            <p>&copy; ' . date('Y') . ' Aroseglink Services Limited. All rights reserved.</p>
        </div>
    </div>
</body>
</html>';

// Email headers
$headers = [];
$headers[] = 'MIME-Version: 1.0';
$headers[] = 'Content-type: text/html; charset=UTF-8';
$headers[] = 'From: Aroseglink Services <noreply@aroseglinkserviceslimited.com>';
$headers[] = 'Reply-To: ' . $name . ' <' . $email . '>';
$headers[] = 'X-Mailer: PHP/' . phpversion();

$mail_sent = false;

// Attempt native PHP mail()
if (function_exists('mail')) {
    $mail_sent = @mail($recipient_email, $subject, $email_body, implode("\r\n", $headers));
}

// Log submission locally to ensure zero data loss
$log_entry = date('Y-m-d H:i:s') . " | Form: $form_type | Name: $name | Email: $email | Phone: $phone | Sent: " . ($mail_sent ? 'YES' : 'NO') . "\n";
@file_put_contents(__DIR__ . '/submissions.log', $log_entry, FILE_APPEND);

// If local mail() failed (typical on local MAMP setups without sendmail daemon), forward via FormSubmit
if (!$mail_sent && function_exists('curl_init')) {
    $ch = curl_init('https://formsubmit.co/ajax/' . $recipient_email);
    $forward_payload = json_encode([
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'form_type' => $form_type,
        'subject' => $subject,
        'destination' => $destination,
        'level' => $level,
        'intake' => $intake,
        'message' => $message,
        '_template' => 'table',
        '_captcha' => 'false'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $forward_payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code >= 200 && $http_code < 300) {
        $mail_sent = true;
    }
}

// Respond with friendly success
echo json_encode([
    'status' => 'success',
    'message' => 'Thank you! Your submission has been received successfully. Our team will contact you shortly.'
]);
exit;

