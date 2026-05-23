<?php
/**
 * LEGALS AI — Single-file PHP application
 * =========================================
 * All backend logic is handled in this file via ?action= routing:
 * ?action=chat         → Groq AI proxy  (was api/chat.php)
 * ?action=submit-lead  → Lead storage   (was api/submit-lead.php)
 *
 * Config (was api/config.php) and helper functions are also inlined below.
 * When no action is requested the file renders the HTML page normally.
 */

// ═══════════════════════════════════════════════════════════════
// SECTION 1 — CONFIGURATION  (was api/config.php)
// ═══════════════════════════════════════════════════════════════

// ── Groq Cloud API ──────────────────────────────────────────
define('GROQ_API_KEY',     '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');
define('GROQ_API_URL',     'https://api.groq.com/openai/v1/chat/completions');
define('GROQ_MODEL',       'llama-3.3-70b-versatile');
define('GROQ_MAX_TOKENS',  1024);
define('GROQ_TEMPERATURE', 0.7);

// ── Lead Storage ────────────────────────────────────────────
define('LEADS_DIR',  __DIR__ . '/leads');
define('LEADS_FILE', LEADS_DIR . '/leads.json');

// ── Lead Distribution Tiers ─────────────────────────────────
$LEAD_TIERS = array(
    'platinum' => array(
        'label'         => 'Platinum Lawyers',
        'percentage'    => 60,
        'priority'      => 1,
        'response_time' => '30 minutes',
        'description'   => 'Premium tier — top-rated, fastest response'
    ),
    'gold' => array(
        'label'         => 'Gold Lawyers',
        'percentage'    => 30,
        'priority'      => 2,
        'response_time' => '2 hours',
        'description'   => 'Standard tier — experienced, reliable'
    ),
    'free' => array(
        'label'         => 'Free Lawyers',
        'percentage'    => 10,
        'priority'      => 3,
        'response_time' => '24 hours',
        'description'   => 'Community tier — pro bono and junior attorneys'
    ),
);

// ── Practice Areas ──────────────────────────────────────────
$PRACTICE_AREAS = array(
    'civil'          => 'Civil Law',
    'criminal'       => 'Criminal Law',
    'family'         => 'Family Law',
    'property'       => 'Property Law',
    'corporate'      => 'Corporate Law',
    'tax'            => 'Tax Law',
    'labour'         => 'Labour & Employment',
    'cyber'          => 'Cyber Law',
    'ip'             => 'Intellectual Property',
    'litigation'     => 'Litigation',
    'consumer'       => 'Consumer Protection',
    'immigration'    => 'Immigration Law',
    'medical'        => 'Medical & Health Law',
    'environmental'  => 'Environmental Law',
    'banking'        => 'Banking & Finance Law',
    'constitutional' => 'Constitutional Law',
);

// ── System Prompt for AI Assistant ──────────────────────────
define('AI_SYSTEM_PROMPT', <<<'AI_SYSTEM_PROMPT'
You are "Legals AI", an intelligent, empathetic legal assistant for an Indian legal services platform called Legals. You help users find the right lawyer for their situation.

CORE BEHAVIOR:
1. You understand natural language — users may NOT use legal terminology. You MUST intelligently map their everyday language to the correct legal practice area.
  - Example: "I want to improve my nose" → could relate to medical malpractice, cosmetic surgery dispute, or consumer protection
  - Example: "My tenant won't leave" → Property Law / Eviction
  - Example: "My boss fired me unfairly" → Labour & Employment Law
  - Example: "Someone copied my app" → Intellectual Property Law

2. Be warm, professional, and reassuring. Users are often stressed about legal issues.

3. Ask follow-up questions ONE AT A TIME. Do NOT overwhelm the user with multiple questions.

4. You must collect ALL of the following information before completing:
  - Understanding of the legal issue (what happened)
  - Practice area (you determine this)
  - Location / City of the user
  - Urgency level (immediate, within a week, not urgent)
  - Budget preference (free consultation, budget-friendly, premium)
  - Contact details: Full Name, Phone Number, Email

5. After collecting ALL required information, you MUST respond with a special JSON block at the END of your message. Format your final message like:
  "Thank you [Name]! I have all the details I need. Let me connect you with the best [practice area] lawyer in [city] right away. You will receive a call within [response time].

  6. NEVER expose this JSON format to the user. It should appear as a hidden comment.

7. If the user asks unrelated questions, gently redirect them to their legal issue.

8. Practice areas available: Civil Law, Criminal Law, Family Law, Property Law, Corporate Law, Tax Law, Labour & Employment, Cyber Law, Intellectual Property, Litigation, Consumer Protection, Immigration Law, Medical & Health Law, Environmental Law, Banking & Finance Law, Constitutional Law.

9. Keep responses concise (2-4 sentences max per reply unless explaining something important).

CONVERSATION FLOW:
- Message 1: Acknowledge their issue warmly, identify possible practice area, ask a clarifying question
- Message 2-4: Ask follow-up questions one at a time (location, urgency, budget)
- Message 5-6: Ask for contact details (name, phone, email)
- Final Message: Confirm all details and include the hidden LEAD_DATA JSON
AI_SYSTEM_PROMPT
);


// ═══════════════════════════════════════════════════════════════
// SECTION 2 — HELPER FUNCTIONS
// ═══════════════════════════════════════════════════════════════

function determineTier($leadNumber, $tiers) {
    $position   = (($leadNumber - 1) % 100) + 1;
    $cumulative = 0;
    foreach ($tiers as $key => $tier) {
        $cumulative += $tier['percentage'];
        if ($position <= $cumulative) {
            return array(
                'key'           => $key,
                'label'         => $tier['label'],
                'response_time' => $tier['response_time'],
                'priority'      => $tier['priority'],
            );
        }
    }
    return array(
        'key'           => 'free',
        'label'         => 'Free Lawyers',
        'response_time' => '24 hours',
        'priority'      => 3,
    );
}

function sendJson($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function setCorsHeaders() {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}


// ═══════════════════════════════════════════════════════════════
// SECTION 3 — ACTION ROUTER
// Handles AJAX requests before any HTML is output.
// ═══════════════════════════════════════════════════════════════

$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($action === 'chat') {
    setCorsHeaders();

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJson(array('error' => 'Method not allowed'), 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || !isset($input['message'])) {
        sendJson(array('error' => 'Missing message field'), 400);
    }

    $userMessage = trim($input['message']);
    $history     = isset($input['history']) ? $input['history'] : array();

    if (empty($userMessage)) {
        sendJson(array('error' => 'Empty message'), 400);
    }

    $messages   = array();
    $messages[] = array('role' => 'system', 'content' => AI_SYSTEM_PROMPT);

    if (is_array($history)) {
        $history = array_slice($history, -20);
        foreach ($history as $msg) {
            if (isset($msg['role']) && isset($msg['content'])) {
                $messages[] = array('role' => $msg['role'], 'content' => $msg['content']);
            }
        }
    }

    $messages[] = array('role' => 'user', 'content' => $userMessage);

    $payload = json_encode(array(
        'model'       => GROQ_MODEL,
        'messages'    => $messages,
        'max_tokens'  => GROQ_MAX_TOKENS,
        'temperature' => GROQ_TEMPERATURE,
        'top_p'       => 0.9,
        'stream'      => false,
    ));

    $ch = curl_init(GROQ_API_URL);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . GROQ_API_KEY,
        ),
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ));

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        sendJson(array('error' => 'Failed to connect to AI service', 'details' => $curlError), 502);
    }

    if ($httpCode !== 200) {
        $errorBody = json_decode($response, true);
        sendJson(array(
            'error'   => 'AI service error',
            'details' => isset($errorBody['error']['message']) ? $errorBody['error']['message'] : 'Unknown error',
            'code'    => $httpCode,
        ), $httpCode);
    }

    $data = json_decode($response, true);

    if (!$data || !isset($data['choices'][0]['message']['content'])) {
        sendJson(array('error' => 'Invalid response from AI service'), 502);
    }

    $aiMessage    = $data['choices'][0]['message']['content'];
    $leadData     = null;
    $cleanMessage = $aiMessage;

    if (preg_match('//s', $aiMessage, $matches)) {
        $leadJson     = trim($matches[1]);
        $leadData     = json_decode($leadJson, true);
        $cleanMessage = trim(preg_replace('//s', '', $aiMessage));
    }

    $result = array(
        'success' => true,
        'message' => $cleanMessage,
        'model'   => isset($data['model'])  ? $data['model']  : GROQ_MODEL,
        'usage'   => isset($data['usage'])  ? $data['usage']  : null,
    );

    if ($leadData && isset($leadData['ready']) && $leadData['ready'] === true) {
        $result['lead_data'] = $leadData;
    }

    sendJson($result);
}

if ($action === 'submit-lead') {
    setCorsHeaders();

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJson(array('error' => 'Method not allowed'), 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        sendJson(array('error' => 'Invalid JSON input'), 400);
    }

    $required = array('name', 'phone', 'practice_area', 'city');
    $missing  = array();
    foreach ($required as $field) {
        if (!isset($input[$field]) || trim($input[$field]) === '') {
            $missing[] = $field;
        }
    }

    if (!empty($missing)) {
        sendJson(array('error' => 'Missing required fields', 'missing' => $missing), 400);
    }

    if (!is_dir(LEADS_DIR)) {
        mkdir(LEADS_DIR, 0755, true);
    }

    $leads = array();
    if (file_exists(LEADS_FILE)) {
        $content = file_get_contents(LEADS_FILE);
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            $leads = $decoded;
        }
    }

    $totalLeads = count($leads) + 1;
    $tier       = determineTier($totalLeads, $GLOBALS['LEAD_TIERS']);

    $lead = array(
        'id'               => 'LEAD-' . str_pad($totalLeads, 6, '0', STR_PAD_LEFT),
        'name'             => trim($input['name']),
        'phone'            => trim($input['phone']),
        'email'            => isset($input['email'])        ? trim($input['email'])        : '',
        'city'             => trim($input['city']),
        'location'         => isset($input['location'])     ? trim($input['location'])     : '',
        'practice_area'    => trim($input['practice_area']),
        'urgency'          => isset($input['urgency'])      ? trim($input['urgency'])      : 'not_specified',
        'budget'           => isset($input['budget'])       ? trim($input['budget'])       : 'not_specified',
        'case_summary'     => isset($input['case_summary']) ? trim($input['case_summary']) : '',
        'ai_category'      => isset($input['ai_category'])  ? trim($input['ai_category'])  : '',
        'assigned_tier'    => $tier['key'],
        'tier_label'       => $tier['label'],
        'response_time'    => $tier['response_time'],
        'status'           => 'new',
        'lawyer_contacted' => false,
        'follow_up_count'  => 0,
        'created_at'       => date('Y-m-d H:i:s'),
        'updated_at'       => date('Y-m-d H:i:s'),
        'source'           => 'ai_chatbot',
    );

    $leads[] = $lead;
    $saved   = file_put_contents(LEADS_FILE, json_encode($leads, JSON_PRETTY_PRINT));

    if ($saved === false) {
        sendJson(array('error' => 'Failed to save lead'), 500);
    }

    sendJson(array(
        'success'       => true,
        'lead_id'       => $lead['id'],
        'assigned_tier' => $tier['label'],
        'response_time' => $tier['response_time'],
        'message'       => 'Lead submitted successfully! A ' . $tier['label'] . ' lawyer will contact you within ' . $tier['response_time'] . '.',
    ));
}

// ═══════════════════════════════════════════════════════════════
// SECTION 4 — HTML PAGE OUTPUT
// (No action matched — render the frontend)
// ═══════════════════════════════════════════════════════════════
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Legals AI — Find the Right Lawyer for Your Case</title>
  <meta name="description" content="Describe your legal issue naturally and our AI assistant will connect you with the best lawyer. Civil, Criminal, Family, Property, Corporate, and more."/>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Cormorant+Garamond:ital,wght@0,400;0,600;1,400&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
  
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/3.3.7/css/bootstrap.min.css">

  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/sweetalert/1.1.3/sweetalert.min.css">

  <style>
  :root {
    --ai-navy: #0A1628;
    --ai-navy-mid: #112240;
    --ai-navy-light: #1B3A5C;
    --ai-gold: #B8960C;
    --ai-gold-light: #D4AF37;
    --ai-gold-pale: #F5EDD0;
    --ai-white: #FAFAF8;
    --ai-off-white: #F4F1EA;
    --ai-text: #2C2C2C;
    --ai-text-muted: #6B7280;
    --ai-border: rgba(184,150,12,0.18);
    --ai-radius: 16px;
    --ai-radius-lg: 28px;
    --ai-radius-pill: 60px;
    --ai-transition: 0.38s cubic-bezier(0.22,1,0.36,1);
    --ai-font: 'Inter', -apple-system, sans-serif;
    --ai-font-display: 'Cormorant Garamond', Georgia, serif;
    --ai-shadow: 0 8px 40px rgba(10,22,40,0.10);
    --ai-shadow-lg: 0 20px 60px rgba(10,22,40,0.15);
  }

  body { font-family: var(--ai-font); color: var(--ai-text); }

  /* ── AI INPUT BOX STYLES ───────────────────────── */
  .ai-input-wrapper {
    margin-bottom: 32px;
    width: 100%;
    max-width:750px;
    margin-left: auto;
    margin-right: auto;
  }

  .ai-input-container {
    display: flex;
    align-items: center;
    background:#FFFFFF;
    border-radius:20px;
    padding: 6px 6px 6px 28px;
    box-shadow: 0 4px 30px rgba(0,0,0,0.12), 0 1px 3px rgba(0,0,0,0.06);
    border: 1.5px solid rgba(0,0,0,0.06);
    transition: 0.38s cubic-bezier(0.22,1,0.36,1);
    position: relative;
  }

  .ai-input-container:focus-within {
    border-color: #B8960C;
    box-shadow: 0 4px 30px rgba(184,150,12,0.15), 0 0 0 3px rgba(184,150,12,0.08);
  }

  .ai-input-container:hover {
    box-shadow: 0 6px 36px rgba(0,0,0,0.14), 0 1px 3px rgba(0,0,0,0.06);
  }

  #aiInputField {
    flex: 1;
    border: none;
    outline: none;
    font-family: 'Inter', -apple-system, sans-serif;
    font-size: 1.5rem;
    color: #2C2C2C;
    background: transparent;
    padding: 14px 12px 14px 0;
    min-width: 0;
  }

  #aiInputField::placeholder {
    color: #9CA3AF;
    font-weight: 400;
  }

  .ai-find-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px; 
    background:#5a3418;
    color: #FAFAF8;
    font-family: 'Inter', -apple-system, sans-serif;
    font-size: 1.5rem;
    font-weight: 700;
    padding: 14px 28px;
    border-radius: 10px;
    border: none;
    cursor: pointer;
    white-space: nowrap;
    transition: 0.38s cubic-bezier(0.22,1,0.36,1);
    letter-spacing: 0.01em;
    flex-shrink: 0;
  }

  .ai-find-btn:hover {
    background:#5a3418;
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(10,22,40,0.3);
  }

  .ai-find-btn:active { 
    transform: translateY(0); 
  }

  .ai-find-btn .sparkle {
    font-size: 1.5rem;
    color: #D4AF37; 
  }

  /* ══════════════════════════════════════════════════════════════
     CHAT OVERLAY UI
     ══════════════════════════════════════════════════════════════ */
  .ai-chat-overlay {
    position: fixed;
    inset: 0;
    z-index: 9999;
    display: none;
    opacity: 0;
    transition: opacity 0.4s ease;
  }

  .ai-chat-overlay.active {
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .ai-chat-overlay.visible { opacity: 1; }

  .ai-chat-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(90, 52, 24, 0.85); 
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
  }

  .ai-chat-panel {
    position: relative;
    z-index: 2;
    width: 94%;
    max-width: 560px;
    height: 85vh;
    max-height: 700px;
    background: var(--ai-white);
    border-radius: var(--ai-radius-lg);
    box-shadow: 0 32px 80px rgba(0,0,0,0.35);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    transform: translateY(30px) scale(0.96);
    transition: transform 0.5s cubic-bezier(0.22,1,0.36,1);
  }

  .ai-chat-overlay.visible .ai-chat-panel {
    transform: translateY(0) scale(1);
  }

  /* ── Chat Header ─────────────────────────────────────────────── */
  .ai-chat-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 18px 24px;
    background: rgb(90, 52, 24); 
    border-bottom: 1px solid rgba(255,255,255,0.08);
    flex-shrink: 0;
  }

  .ai-chat-header-info {
    display: flex;
    align-items: center;
    gap: 12px;
  }

  .ai-chat-avatar {
    width: 40px;
    height: 40px;
    background: rgb(204, 164, 100); 
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
  }

  .ai-chat-header-text h3 {
    font-family: var(--ai-font);
    font-size: 1.5rem;
    font-weight: 600;
    color: var(--ai-white);
    margin: 0;
  }

  .ai-chat-header-text span {
    font-family: var(--ai-font);
    font-size: 0.72rem;
    color: rgba(255,255,255,0.5);
    display: flex;
    align-items: center;
    gap: 4px;
  }

  .ai-online-dot {
    width: 6px; height: 6px;
    background: #34D399;
    border-radius: 50%;
    display: inline-block;
    animation: pulseDot 2s infinite;
  }

  .ai-chat-close {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    border: 1px solid rgba(255,255,255,0.12);
    background: rgba(255,255,255,0.06);
    color: rgba(255,255,255,0.6);
    font-size: 1.2rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: var(--ai-transition);
  }

  .ai-chat-close:hover {
    background: rgba(255,255,255,0.12);
    color: var(--ai-white);
  }

  /* ── Chat Messages Area ──────────────────────────────────────── */
  .ai-chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 24px 20px;
    display: flex;
    flex-direction: column;
    gap: 16px;
    background: #F8F9FB;
    scroll-behavior: smooth;
  }

  .ai-chat-messages::-webkit-scrollbar { width: 5px; }
  .ai-chat-messages::-webkit-scrollbar-track { background: transparent; }
  .ai-chat-messages::-webkit-scrollbar-thumb { background: #D1D5DB; border-radius: 10px; }

  /* Message bubbles */
  .ai-msg {
    display: flex;
    gap: 10px;
    max-width: 85%;
    animation: msgSlideIn 0.35s ease-out;
  }

  .ai-msg.ai { align-self: flex-start; }
  .ai-msg.user { align-self: flex-end; flex-direction: row-reverse; }

  .ai-msg-avatar {
    width: 32px;
    height: 32px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.85rem;
    flex-shrink: 0;
    margin-top: 2px;
  }

  .ai-msg.ai .ai-msg-avatar {
    background: rgb(204, 164, 100); 
    color: rgb(90, 52, 24); 
  }

  .ai-msg.user .ai-msg-avatar {
    background: rgb(90, 52, 24); 
    color: var(--ai-white);
    font-size: 0.7rem;
    font-weight: 600;
  }

  .ai-msg-bubble {
    padding: 14px 18px;
    border-radius: 18px;
    font-family: var(--ai-font);
    font-size: 0.9rem;
    line-height: 1.6;
    position: relative;
  }

  .ai-msg.ai .ai-msg-bubble {
    background: var(--ai-white);
    color: var(--ai-text);
    border: 1px solid rgba(0,0,0,0.06);
    border-bottom-left-radius: 6px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.04);
  }

  .ai-msg.user .ai-msg-bubble {
    background: rgb(90, 52, 24); 
    color: var(--ai-white);
    border-bottom-right-radius: 6px;
  }

  .ai-msg-time {
    font-size: 0.65rem;
    color: var(--ai-text-muted);
    margin-top: 4px;
    display: block;
  }

  .ai-msg.user .ai-msg-time { text-align: right; color: rgba(255,255,255,0.4); }

  /* Typing indicator */
  .ai-typing {
    display: flex;
    gap: 10px;
    max-width: 85%;
    align-self: flex-start;
    animation: msgSlideIn 0.3s ease-out;
  }

  .ai-typing-dots {
    padding: 16px 22px;
    background: var(--ai-white);
    border: 1px solid rgba(0,0,0,0.06);
    border-radius: 18px;
    border-bottom-left-radius: 6px;
    display: flex;
    align-items: center;
    gap: 5px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.04);
  }

  .ai-typing-dots span {
    width: 7px;
    height: 7px;
    background: #B0B8C4;
    border-radius: 50%;
    animation: typingBounce 1.4s infinite;
  }

  .ai-typing-dots span:nth-child(2) { animation-delay: 0.15s; }
  .ai-typing-dots span:nth-child(3) { animation-delay: 0.3s; }

  @keyframes typingBounce {
    0%, 60%, 100% { transform: translateY(0); opacity: 0.4; }
    30% { transform: translateY(-6px); opacity: 1; }
  }

  /* ── Chat Input Area ─────────────────────────────────────────── */
  .ai-chat-input-area {
    padding: 16px 20px;
    background: var(--ai-white);
    border-top: 1px solid rgba(0,0,0,0.06);
    flex-shrink: 0;
  }

  .ai-chat-input-row {
    display: flex;
    align-items: center;
    gap: 10px;
    background: #F3F4F6;
    border-radius: var(--ai-radius-pill);
    padding: 4px 4px 4px 20px;
    border: 1.5px solid transparent;
    transition: var(--ai-transition);
  }

  .ai-chat-input-row:focus-within {
    border-color: rgb(204, 164, 100); 
    background: var(--ai-white);
    box-shadow: 0 0 0 3px rgba(204, 164, 100, 0.08); 
  }

  #chatMessageInput {
    flex: 1;
    border: none;
    outline: none;
    font-family: var(--ai-font);
    font-size: 0.9rem;
    color: var(--ai-text);
    background: transparent;
    padding: 12px 0;
    min-width: 0;
  }

  #chatMessageInput::placeholder { color: #9CA3AF; }

  .ai-chat-send-btn {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    border: none;
    background: rgb(90, 52, 24); 
    color: var(--ai-white);
    font-size: 1.1rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: var(--ai-transition);
    flex-shrink: 0;
  }

  .ai-chat-send-btn:hover {
    background: rgb(204, 164, 100); 
    transform: scale(1.06);
  }

  .ai-chat-send-btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
    transform: none;
  }

  .ai-chat-disclaimer {
    font-family: var(--ai-font);
    font-size: 0.68rem;
    color: var(--ai-text-muted);
    text-align: center;
    margin-top: 8px;
  }

  /* ── Lead Success Card ───────────────────────────────────────── */
  .ai-lead-success {
    background: linear-gradient(135deg, #ECFDF5, #D1FAE5);
    border: 1px solid #6EE7B7;
    border-radius: 16px;
    padding: 20px;
    text-align: center;
    animation: msgSlideIn 0.4s ease-out;
    align-self: center;
    max-width: 90%;
  }

  .ai-lead-success .success-icon {
    font-size: 2.4rem;
    margin-bottom: 8px;
    display: block;
  }

  .ai-lead-success h4 {
    font-family: var(--ai-font);
    font-size: 1rem;
    font-weight: 600;
    color: #065F46;
    margin-bottom: 4px;
  }

  .ai-lead-success p {
    font-family: var(--ai-font);
    font-size: 0.82rem;
    color: #047857;
    line-height: 1.5;
  }

  .ai-lead-success .tier-badge {
    display: inline-block;
    background: var(--ai-gold);
    color: var(--ai-navy);
    font-size: 0.72rem;
    font-weight: 600;
    padding: 4px 14px;
    border-radius: 100px;
    margin-top: 10px;
    letter-spacing: 0.05em;
  }

  /* ── RESPONSIVE ──────────────────────────────────────────────── */
  @media (max-width: 640px) {
    .ai-input-container { padding: 4px 4px 4px 18px; }
    #aiInputField { font-size: 0.88rem; padding: 12px 8px 12px 0; }
    .ai-find-btn { padding: 12px 18px; font-size: 0.82rem; }

    .ai-chat-panel {
      width: 100%;
      height: 100vh;
      max-height: 100vh;
      border-radius: 0;
    }

    .ai-chat-messages { padding: 16px 14px; }
    .ai-msg { max-width: 92%; }
    .ai-msg-bubble { padding: 12px 14px; font-size: 0.85rem; }
  }

  @media (max-width: 400px) {
    .ai-find-btn span.btn-text { display: none; }
    .ai-find-btn { padding: 12px 16px; }
  }
  </style>
</head>
<body>

  <div class="container text-center" style="margin-top: 100px;">
    <div class="ai-input-wrapper">
      <div class="ai-input-container" id="aiInputContainer">
       <input
          type="text"
          id="aiInputField"
          placeholder="e.g. My tenant is not vacating my home..."
          autocomplete="off"
          aria-label="Describe your legal issue"
        /> 
        <button class="ai-find-btn" id="aiFindHelpBtn" type="button">
          <i class="fa fa-magic sparkle"></i> 
          <span class="btn-text">Find Help</span> 
        </button>
      </div>
    </div>
  </div>

  <div class="ai-chat-overlay" id="aiChatOverlay">
    <div class="ai-chat-backdrop"></div>
    <div class="ai-chat-panel">

      <div class="ai-chat-header">
        <div class="ai-chat-header-info">
          <div class="ai-chat-avatar"><i class="fa fa-balance-scale" style="color: rgb(90, 52, 24);"></i></div>
          <div class="ai-chat-header-text">
            <h3>Legals AI Assistant</h3>
            <span><span class="ai-online-dot"></span> Online · Powered by AI</span>
          </div>
        </div>
        <button class="ai-chat-close" id="aiChatClose" aria-label="Close chat">
          <i class="fa fa-times"></i>
        </button>
      </div>

      <div class="ai-chat-messages" id="aiChatMessages"></div>

      <div class="ai-chat-input-area">
        <div class="ai-chat-input-row">
          <input
            type="text"
            id="chatMessageInput"
            placeholder="Type your message..."
            autocomplete="off"
            aria-label="Chat message"
          />
          <button class="ai-chat-send-btn" id="aiChatSendBtn" type="button" aria-label="Send message">
            <i class="fa fa-paper-plane"></i>
          </button>
        </div>
        <p class="ai-chat-disclaimer">
          <i class="fa fa-lock" style="color: rgb(204, 164, 100);"></i> Your information is protected by attorney-client privilege.
        </p>
      </div>

    </div>
  </div>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/sweetalert/1.1.3/sweetalert.min.js"></script>

  <script>
  (function () {
    'use strict';

    var API_CHAT_URL = 'index.php?action=chat';
    var API_LEAD_URL = 'index.php?action=submit-lead';

    var conversationHistory = [];
    var isProcessing = false;
    var leadSubmitted = false;

    var $aiInput      = $('#aiInputField');
    var $findBtn      = $('#aiFindHelpBtn');
    var $chatOverlay  = $('#aiChatOverlay');
    var $chatMessages = $('#aiChatMessages');
    var $chatInput    = $('#chatMessageInput');
    var $chatSendBtn  = $('#aiChatSendBtn');
    var $chatClose    = $('#aiChatClose');

    $findBtn.on('click', function () {
      var userText = $.trim($aiInput.val());
      if (!userText) {
        $aiInput.focus();
        $aiInput.css('border-color', '#EF4444');
        setTimeout(function () {
          $aiInput.css('border-color', '');
        }, 1500);
        return;
      }
      openChat(userText);
    });

    $aiInput.on('keydown', function (e) {
      if (e.which === 13) {
        e.preventDefault();
        $findBtn.trigger('click');
      }
    });

    function openChat(initialMessage) {
      conversationHistory = [];
      leadSubmitted = false;
      $chatMessages.empty();

      $chatOverlay.addClass('active');
      setTimeout(function () {
        $chatOverlay.addClass('visible');
      }, 20);

      $('body').css('overflow', 'hidden');

      addMessage('ai', "Hello! I'm your Legals AI assistant. I'll help you find the perfect lawyer for your situation. Let me understand your case better.");

      setTimeout(function () {
        addMessage('user', initialMessage);
        sendToAI(initialMessage);
      }, 600);

      setTimeout(function () {
        $chatInput.focus();
      }, 800);
    }

    $chatClose.on('click', closeChat);

    function closeChat() {
      $chatOverlay.removeClass('visible');
      setTimeout(function () {
        $chatOverlay.removeClass('active');
        $('body').css('overflow', '');
      }, 400);
    }

    $chatOverlay.on('click', function (e) {
      if ($(e.target).hasClass('ai-chat-backdrop')) {
        closeChat();
      }
    });

    $(document).on('keydown', function (e) {
      if (e.which === 27 && $chatOverlay.hasClass('active')) {
        closeChat();
      }
    });

    $chatSendBtn.on('click', function () {
      sendUserMessage();
    });

    $chatInput.on('keydown', function (e) {
      if (e.which === 13 && !e.shiftKey) {
        e.preventDefault();
        sendUserMessage();
      }
    });

    function sendUserMessage() {
      if (isProcessing || leadSubmitted) return;

      var text = $.trim($chatInput.val());
      if (!text) return;

      $chatInput.val('');
      addMessage('user', text);
      sendToAI(text);
    }

    function addMessage(role, content) {
      var time = new Date();
      var timeStr = time.getHours().toString().padStart(2, '0') + ':' + time.getMinutes().toString().padStart(2, '0');

      var avatarContent = role === 'ai' ? '<i class="fa fa-balance-scale" style="color: rgb(90, 52, 24);"></i>' : 'You';
      var displayContent = role === 'user' ? escapeHtml(content) : content;

      var html = '<div class="ai-msg ' + role + '">' +
        '<div class="ai-msg-avatar">' + avatarContent + '</div>' +
        '<div>' +
          '<div class="ai-msg-bubble">' + displayContent + '</div>' +
          '<span class="ai-msg-time">' + timeStr + '</span>' +
        '</div>' +
      '</div>';

      $chatMessages.append(html);
      scrollToBottom();
    }

    function addLeadSuccessCard(tierLabel, responseTime, leadId) {
      var html = '<div class="ai-lead-success">' +
        '<span class="success-icon">🎉</span>' +
        '<h4>Lead Submitted Successfully!</h4>' +
        '<p>Your case has been registered as <strong>' + escapeHtml(leadId) + '</strong>.<br>' +
          'A <strong>' + escapeHtml(tierLabel) + '</strong> lawyer will contact you within <strong>' + escapeHtml(responseTime) + '</strong>.</p>' +
        '<span class="tier-badge">' + escapeHtml(tierLabel) + '</span>' +
      '</div>';

      $chatMessages.append(html);
      scrollToBottom();
    }

    function showTyping() {
      var html = '<div class="ai-typing" id="aiTypingIndicator">' +
        '<div class="ai-msg-avatar" style="background:rgb(204, 164, 100);"><i class="fa fa-balance-scale" style="color: rgb(90, 52, 24);"></i></div>' +
        '<div class="ai-typing-dots"><span></span><span></span><span></span></div>' +
      '</div>';
      $chatMessages.append(html);
      scrollToBottom();
    }

    function hideTyping() {
      $('#aiTypingIndicator').remove();
    }

    function sendToAI(userMessage) {
      if (isProcessing) return;
      isProcessing = true;

      conversationHistory.push({ role: 'user', content: userMessage });

      $chatSendBtn.prop('disabled', true);
      $chatInput.prop('disabled', true);

      showTyping();

      $.ajax({
        url: API_CHAT_URL,
        type: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({
          message: userMessage,
          history: conversationHistory
        }),
        dataType: 'json',
        timeout: 35000,
        success: function (res) {
          hideTyping();

          if (res.success && res.message) {
            conversationHistory.push({ role: 'assistant', content: res.message });
            addMessage('ai', formatAIMessage(res.message));

            if (res.lead_data && res.lead_data.ready) {
              submitLead(res.lead_data);
            }
          } else {
            addMessage('ai', 'I apologize, I encountered an issue. Could you please rephrase your question?');
          }

          isProcessing = false;
          $chatSendBtn.prop('disabled', false);
          $chatInput.prop('disabled', false);
          $chatInput.focus();
        },
        error: function (xhr) {
          hideTyping();
          isProcessing = false;
          $chatSendBtn.prop('disabled', false);
          $chatInput.prop('disabled', false);

          var errMsg = 'I apologize, there seems to be a connection issue. Please try again.';
          try {
            var errData = JSON.parse(xhr.responseText);
            if (errData.error) {
              errMsg = 'Sorry, I encountered an issue: ' + errData.error + '. Please try again.';
            }
          } catch (e) {}

          addMessage('ai', errMsg);
          $chatInput.focus();
        }
      });
    }

    function submitLead(leadData) {
      if (leadSubmitted) return;

      $.ajax({
        url: API_LEAD_URL,
        type: 'POST',
        contentType: 'application/json',
        data: JSON.stringify(leadData),
        dataType: 'json',
        timeout: 15000,
        success: function (res) {
          if (res.success) {
            leadSubmitted = true;
            addLeadSuccessCard(res.assigned_tier, res.response_time, res.lead_id);

            $chatInput.prop('disabled', true).attr('placeholder', 'Lead submitted — chat ended');
            $chatSendBtn.prop('disabled', true);

            if (typeof swal !== 'undefined') {
              swal({
                title: 'Lead Submitted!',
                text: res.message,
                type: 'success',
                confirmButtonText: 'Great!'
              });
            }
          }
        },
        error: function () {
          addMessage('ai', 'Your details have been noted. Our team will reach out to you shortly.');
        }
      });
    }

    function scrollToBottom() {
      var el = $chatMessages[0];
      if (el) {
        setTimeout(function () {
          el.scrollTop = el.scrollHeight;
        }, 50);
      }
    }

    function escapeHtml(str) {
      if (!str) return '';
      return str.replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
    }

    function formatAIMessage(text) {
      if (!text) return '';
      text = text.replace(/\n/g, '<br>');
      text = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
      return text;
    }

    if (!String.prototype.padStart) {
      String.prototype.padStart = function (len, fill) {
        var s = String(this);
        fill = fill || ' ';
        while (s.length < len) s = fill + s;
        return s;
      };
    }

  })();
  </script>
</body>
</html>