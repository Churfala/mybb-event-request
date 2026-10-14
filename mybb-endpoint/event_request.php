<?php
/**
 * Event Request Intake Endpoint
 *
 * A machine-to-machine endpoint that lets an external site (the garrison's public
 * WordPress site) create event requests in the Event Management plugin without scraping
 * posts or writing to its tables directly. It builds the same validated input the event
 * form builds and hands it to events_save_event(), so an intake event goes through the
 * plugin's own validation, day handling, locking and thread generation - there is one way
 * an event is created, and this is another caller of it rather than a second copy.
 *
 * Contributed to the Southern Cross Garrison plugin by a member garrison for
 * integration. Written to match the plugin's conventions so it can be merged into it;
 * drop it at the forum root beside events.php, event.php and the other front-end scripts.
 *
 * Two actions:
 *   GET  ?action=config  -> the live region and event-type lists, so the requesting form's
 *                           dropdowns always match what the board is configured with.
 *   POST (default)       -> create one event, status 'pending', for staff to review and set
 *                           live. No announcement thread is posted until it is set live, the
 *                           same as any other pending event.
 *
 * Both require the shared bearer token in the Authorization header. The token is only ever
 * held by the calling server, never sent to a browser, so the public form cannot carry it.
 */

define("IN_MYBB", 1);

// ============================================================
// CONFIGURATION - edit these for the board
// ============================================================

// The shared secret the calling server sends as "Authorization: Bearer <token>".
// Generate a long random value and set the same one on the WordPress side. Anyone holding
// this can create pending events, so treat it like a password.
define('EVENTS_INTAKE_TOKEN', 'CHANGE-ME-to-a-long-random-secret');

// The MyBB user intake events are created as. It is recorded as the event's creator and is
// the default coordinator, so it must be a real account that is in one of the Event
// Coordinator User Groups (Admin CP -> Event Management -> Settings). A dedicated
// "Event Intake" account is cleanest; the event's real coordinator is assigned by staff on
// review.
define('EVENTS_INTAKE_UID', 0);

// The status an intake event is created with. 'pending' mirrors a moderation queue: no
// announcement thread is posted until staff set it live. Leave this as 'pending' unless you
// want public requests to go live unseen.
define('EVENTS_INTAKE_STATUS', 'pending');

// The event type an intake request is created as. 'troop' or 'social'.
define('EVENTS_INTAKE_EVENT_TYPE', 'troop');

// ============================================================

require_once "./global.php";
require_once MYBB_ROOT . "inc/plugins/events/inc/events_functions.php";
require_once MYBB_ROOT . "inc/plugins/events/inc/events_form.php";

/**
 * Answer with a JSON body and an HTTP status, and stop.
 *
 * @param int $status
 * @param array $body
 * @return void
 */
function events_intake_respond($status, array $body)
{
    global $charset;

    if(!headers_sent())
    {
        http_response_code((int)$status);
    }
    @header("Content-Type: application/json; charset=" . ($charset ? $charset : 'utf-8'));
    // An intake answer is never cacheable - each is about one request.
    @header("Cache-Control: no-store");

    echo json_encode($body);
    exit;
}

/**
 * The bearer token the caller sent, or '' if none.
 *
 * Some servers hand the Authorization header to PHP under a different name, or strip it, so
 * both the usual place and Apache's own copy are checked.
 *
 * @return string
 */
function events_intake_bearer_token()
{
    $header = '';
    if(isset($_SERVER['HTTP_AUTHORIZATION']))
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'];
    }
    elseif(isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']))
    {
        $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    elseif(function_exists('apache_request_headers'))
    {
        $headers = apache_request_headers();
        foreach($headers as $name => $value)
        {
            if(strtolower($name) === 'authorization')
            {
                $header = $value;
                break;
            }
        }
    }

    if(preg_match('/^\s*Bearer\s+(.+)$/i', trim($header), $m))
    {
        return trim($m[1]);
    }

    return '';
}

/**
 * Turn the caller away unless it sent the shared token. Compared in constant time so the
 * endpoint is not a timing oracle for the secret.
 *
 * @return void
 */
function events_intake_require_token()
{
    $sent = events_intake_bearer_token();

    if(EVENTS_INTAKE_TOKEN === 'CHANGE-ME-to-a-long-random-secret' || EVENTS_INTAKE_TOKEN === '')
    {
        events_intake_respond(503, array(
            'ok'    => false,
            'error' => 'The intake endpoint has not been configured with a token yet.',
        ));
    }

    if($sent === '' || !hash_equals(EVENTS_INTAKE_TOKEN, $sent))
    {
        events_intake_respond(401, array(
            'ok'    => false,
            'error' => 'Unauthorized.',
        ));
    }
}

/**
 * Load the intake user into $mybb->user.
 *
 * events_validate_event_input() reads $mybb->user to work out which coordinators and points
 * of contact are on offer, so the endpoint has to act as a real member rather than the guest
 * global.php leaves it as for a request carrying no session. The intake user is always an
 * allowed coordinator because the saving user always is.
 *
 * @return array The intake user
 */
function events_intake_user()
{
    global $mybb;

    $user = get_user(EVENTS_INTAKE_UID);
    if(empty($user['uid']))
    {
        events_intake_respond(503, array(
            'ok'    => false,
            'error' => 'The intake endpoint is configured with a user that does not exist.',
        ));
    }

    $mybb->user = $user;

    return $user;
}

/**
 * A single line of text from the request, trimmed, with newlines flattened to spaces.
 *
 * @param array $data
 * @param string $key
 * @return string
 */
function events_intake_line($data, $key)
{
    $value = isset($data[$key]) ? (string)$data[$key] : '';

    return trim(preg_replace('/\s+/', ' ', $value));
}

/**
 * A block of text from the request, trimmed, keeping its line breaks.
 *
 * @param array $data
 * @param string $key
 * @return string
 */
function events_intake_text($data, $key)
{
    $value = isset($data[$key]) ? (string)$data[$key] : '';

    return trim(str_replace(array("\r\n", "\r"), "\n", $value));
}

/**
 * Compose the event description from the request's own description and the details block the
 * caller composed - the requester's contact details and the answers to whatever extra
 * questions that garrison's form asks.
 *
 * The caller sends the block as plain "Label: value" lines because it is the side that knows
 * the questions; this end only wraps it under a heading and applies the board's own markup.
 * So a garrison adding a question never touches this endpoint - the new answer simply appears
 * in request_details.
 *
 * @param array $data
 * @return string
 */
function events_intake_description($data)
{
    $description = events_intake_text($data, 'description');
    $details = events_intake_text($data, 'request_details');

    if($details === '')
    {
        return $description;
    }

    // The lines arrive as plain "Label: value"; bold the label this side so the block reads
    // as part of a MyBB post without the caller needing to know BBCode.
    $lines = array();
    foreach(explode("\n", $details) as $line)
    {
        $line = trim($line);
        if($line === '')
        {
            continue;
        }
        if(strpos($line, ':') !== false)
        {
            list($label, $value) = explode(':', $line, 2);
            $lines[] = "[b]" . trim($label) . ":[/b] " . trim($value);
        }
        else
        {
            $lines[] = $line;
        }
    }

    $block = "[b][u]Event Request Details[/u][/b]\n" . implode("\n", $lines);

    return $description === '' ? $block : $description . "\n\n" . $block;
}

/**
 * Build a stored datetime (Y-m-d H:i:s) from a request's date and optional time, or '' when
 * there is no date. The value is handed to the plugin's own validator, which rejects a date
 * that is not real, so this only has to shape it.
 *
 * @param array $data
 * @param string $date_key
 * @param string $time_key
 * @return string
 */
function events_intake_datetime($data, $date_key, $time_key)
{
    $date = events_intake_line($data, $date_key);
    if($date === '')
    {
        return '';
    }

    // Accept a date that already carries its time, otherwise add the time field.
    if(strlen($date) > 10)
    {
        return $date;
    }

    $time = events_intake_line($data, $time_key);
    if($time === '')
    {
        return $date;
    }

    // Normalise H:MM to H:MM:00 so it reaches the DATETIME column whole.
    if(preg_match('/^(\d{1,2}):(\d{2})$/', $time))
    {
        $time .= ':00';
    }

    return $date . ' ' . $time;
}

/**
 * The config action: the region and event-type lists the requesting form draws its
 * dropdowns from, so a request can only ever name a region the board actually has.
 *
 * @return void
 */
function events_intake_config()
{
    $regions = events_regions();

    $types = array();
    foreach(events_event_types() as $key => $label)
    {
        $types[] = array('value' => $key, 'label' => $label);
    }

    events_intake_respond(200, array(
        'ok'          => true,
        'regions'     => array_values($regions),
        'event_types' => $types,
        'wwcc_name'   => function_exists('events_wwcc_name') ? events_wwcc_name() : '',
        'title_max'   => defined('EVENTS_TITLE_MAX_LENGTH') ? EVENTS_TITLE_MAX_LENGTH : 64,
    ));
}

/**
 * The create action: read the request, build the plugin's event input, validate it and save
 * it as a pending event.
 *
 * @return void
 */
function events_intake_create()
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if(!is_array($data))
    {
        events_intake_respond(400, array(
            'ok'    => false,
            'error' => 'The request body must be a JSON object.',
        ));
    }

    events_intake_user();

    // Title is capped rather than refused: a public event name can run long, and a request
    // bounced for length is a request lost. The source form should warn, but the saved
    // event is never rejected over it here.
    $title = events_intake_line($data, 'title');
    if(defined('EVENTS_TITLE_MAX_LENGTH') && my_strlen($title) > EVENTS_TITLE_MAX_LENGTH)
    {
        $title = my_substr($title, 0, EVENTS_TITLE_MAX_LENGTH);
    }

    // The same shape events_event_form_input() returns, built from the request instead of
    // from a posted form. Fields the public form does not collect take their safe defaults:
    // no limits, no point of contact, no exclusions, one day described by start and end.
    $input = array(
        'title'               => $title,
        'event_type'          => EVENTS_INTAKE_EVENT_TYPE,
        'description'         => events_intake_description($data),
        'status'              => EVENTS_INTAKE_STATUS,
        'region'              => events_intake_line($data, 'region'),
        'address'             => events_intake_line($data, 'address'),
        'external_signup_url' => '',
        'start_date'          => events_intake_datetime($data, 'start_date', 'start_time'),
        'end_date'            => events_intake_datetime($data, 'end_date', 'end_time'),
        'signup_cutoff'       => '',
        'requires_wwcc'       => 0,
        'gec_user_id'         => (int)EVENTS_INTAKE_UID,
        'poc_user_id'         => 0,
        'max_troopers'        => '',
        'max_wranglers'       => '',
        'max_attendees'       => '',
        'days'                => array(),
        'exclusions'          => '',
    );

    $errors = events_validate_event_input($input);
    if(!empty($errors))
    {
        events_intake_respond(422, array(
            'ok'     => false,
            'error'  => 'The event request could not be validated.',
            'errors' => array_values($errors),
        ));
    }

    $thread_error = null;
    $event_id = events_save_event(0, $input, (int)EVENTS_INTAKE_UID, $thread_error);

    events_intake_respond(201, array(
        'ok'           => true,
        'event_id'     => (int)$event_id,
        'status'       => EVENTS_INTAKE_STATUS,
        // A pending event posts no thread, so a thread error here would be unusual; it is
        // passed back rather than swallowed so the caller can log it.
        'thread_error' => $thread_error ? (string)$thread_error : null,
    ));
}

// --- Route -----------------------------------------------------------------

events_intake_require_token();

if($mybb->request_method === 'post')
{
    events_intake_create();
}
elseif($mybb->get_input('action') === 'config')
{
    events_intake_config();
}
else
{
    events_intake_respond(405, array(
        'ok'    => false,
        'error' => 'POST an event request, or GET ?action=config.',
    ));
}
