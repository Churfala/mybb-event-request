<?php
/**
 * Plugin Name: MyBB Event Request
 * Description: A public event-request form that submits straight into the MyBB Event Management plugin's intake endpoint. Replaces the Contact Form 7 + bridge approach. Extra questions are configurable per garrison.
 * Version: 1.1.0
 * Author: Garrison Web Team
 * License: Apache-2.0
 */

if (!defined('ABSPATH')) exit;

define('EVREQ_OPTION', 'evreq_settings');
define('EVREQ_CONFIG_TRANSIENT', 'evreq_remote_config');
define('EVREQ_RATELIMIT_PREFIX', 'evreq_rl_');

/** The custom-question control types an admin can choose. */
function evreq_question_types() {
    return array(
        'text'     => 'Single line',
        'textarea' => 'Paragraph',
        'select'   => 'Dropdown (choose one)',
        'checkbox' => 'Checkboxes (choose any)',
    );
}

// ============================================================
// Settings
// ============================================================

/**
 * The plugin's settings, with defaults.
 *
 * @return array
 */
function evreq_settings() {
    $defaults = array(
        'endpoint_url'     => '',   // e.g. https://forum.example.org/event_request.php
        'token'            => '',   // the shared bearer token - matches EVENTS_INTAKE_TOKEN on MyBB
        'success_text'     => 'Thanks! Your event request has been sent for review.',
        'custom_questions' => array(),  // array of {label, type, options[], required}
    );

    $settings = wp_parse_args(get_option(EVREQ_OPTION, array()), $defaults);
    $settings['custom_questions'] = evreq_normalize_questions($settings['custom_questions']);

    return $settings;
}

/**
 * Clean a set of custom questions into a predictable, reindexed shape.
 *
 * @param mixed $questions
 * @return array
 */
function evreq_normalize_questions($questions) {
    $clean = array();
    if (!is_array($questions)) {
        return $clean;
    }

    $types = evreq_question_types();
    foreach ($questions as $q) {
        if (!is_array($q)) {
            continue;
        }
        $label = isset($q['label']) ? trim(wp_strip_all_tags($q['label'])) : '';
        if ($label === '') {
            // A row with no label was never a real question - drop it.
            continue;
        }

        $type = isset($q['type']) && isset($types[$q['type']]) ? $q['type'] : 'text';

        $options = array();
        if (in_array($type, array('select', 'checkbox'), true)) {
            $raw = isset($q['options']) ? $q['options'] : array();
            // Stored as an array, but the admin textarea posts it as newline-separated text.
            if (is_string($raw)) {
                $raw = preg_split('/\r\n|\r|\n/', $raw);
            }
            foreach ((array)$raw as $opt) {
                $opt = trim(wp_strip_all_tags($opt));
                if ($opt !== '') {
                    $options[] = $opt;
                }
            }
        }

        $clean[] = array(
            'label'    => $label,
            'type'     => $type,
            'options'  => $options,
            'required' => !empty($q['required']) ? 1 : 0,
        );
    }

    return $clean;
}

add_action('admin_menu', 'evreq_admin_menu');
function evreq_admin_menu() {
    add_options_page('Event Request', 'Event Request', 'manage_options', 'evreq', 'evreq_settings_page');
}

add_action('admin_init', 'evreq_register_settings');
function evreq_register_settings() {
    register_setting('evreq', EVREQ_OPTION, 'evreq_sanitize_settings');
}

/**
 * @param array $input
 * @return array
 */
function evreq_sanitize_settings($input) {
    $clean = array();
    $clean['endpoint_url']     = isset($input['endpoint_url']) ? esc_url_raw(trim($input['endpoint_url'])) : '';
    $clean['token']            = isset($input['token']) ? trim($input['token']) : '';
    $clean['success_text']     = isset($input['success_text']) ? sanitize_text_field($input['success_text']) : '';
    $clean['custom_questions'] = evreq_normalize_questions(isset($input['custom_questions']) ? $input['custom_questions'] : array());

    // Any settings change invalidates the cached region/type list.
    delete_transient(EVREQ_CONFIG_TRANSIENT);

    return $clean;
}

add_action('admin_enqueue_scripts', 'evreq_admin_assets');
function evreq_admin_assets($hook) {
    if ($hook !== 'settings_page_evreq') {
        return;
    }
    wp_enqueue_style('evreq-admin', plugins_url('assets/admin.css', __FILE__), array(), '1.1.0');
    wp_enqueue_script('evreq-admin', plugins_url('assets/admin.js', __FILE__), array(), '1.1.0', true);
}

function evreq_settings_page() {
    $settings = evreq_settings();
    $config = evreq_remote_config(true);
    $types = evreq_question_types();
    ?>
    <div class="wrap">
        <h1>Event Request</h1>
        <form method="post" action="options.php">
            <?php settings_fields('evreq'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="evreq_endpoint">MyBB endpoint URL</label></th>
                    <td>
                        <input name="<?php echo EVREQ_OPTION; ?>[endpoint_url]" id="evreq_endpoint" type="url"
                               class="regular-text" value="<?php echo esc_attr($settings['endpoint_url']); ?>"
                               placeholder="https://forum.example.org/event_request.php">
                        <p class="description">The full URL to <code>event_request.php</code> on the forum.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="evreq_token">Shared token</label></th>
                    <td>
                        <input name="<?php echo EVREQ_OPTION; ?>[token]" id="evreq_token" type="password"
                               class="regular-text" value="<?php echo esc_attr($settings['token']); ?>"
                               autocomplete="new-password">
                        <p class="description">Must match <code>EVENTS_INTAKE_TOKEN</code> on the forum. Kept on the server, never sent to the browser.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="evreq_success">Success message</label></th>
                    <td>
                        <input name="<?php echo EVREQ_OPTION; ?>[success_text]" id="evreq_success" type="text"
                               class="large-text" value="<?php echo esc_attr($settings['success_text']); ?>">
                    </td>
                </tr>
            </table>

            <h2>Custom questions</h2>
            <p class="description">The core fields (event name, region, venue, date, times, description, and the
                requester's name/email/phone) are always shown. Add any extra questions your garrison asks here.
                Answers are added to the event's description for staff to read.</p>

            <div id="evreq-questions">
                <?php
                $rows = $settings['custom_questions'];
                // One empty row to start an otherwise-blank list.
                if (empty($rows)) {
                    $rows = array(array('label' => '', 'type' => 'text', 'options' => array(), 'required' => 0));
                }
                foreach ($rows as $i => $q) {
                    evreq_render_question_row($i, $q, $types);
                }
                ?>
            </div>

            <p><button type="button" class="button" id="evreq-add-question">+ Add question</button></p>

            <!-- Template for new rows; {{i}} is replaced with a fresh index by admin.js. -->
            <script type="text/html" id="evreq-question-template">
                <?php evreq_render_question_row('{{i}}', array('label' => '', 'type' => 'text', 'options' => array(), 'required' => 0), $types); ?>
            </script>

            <?php submit_button(); ?>
        </form>

        <h2>Connection</h2>
        <?php if (is_wp_error($config)) : ?>
            <p style="color:#b32d2e;">Could not reach the endpoint: <?php echo esc_html($config->get_error_message()); ?></p>
        <?php elseif (empty($config['regions'])) : ?>
            <p>Connected, but the board has no regions configured.</p>
        <?php else : ?>
            <p style="color:#1a7f37;">Connected. Regions available to the form:
                <strong><?php echo esc_html(implode(', ', $config['regions'])); ?></strong>.</p>
        <?php endif; ?>

        <h2>Usage</h2>
        <p>Place the form on any page or post with the shortcode: <code>[mybb_event_request]</code></p>
    </div>
    <?php
}

/**
 * One editable custom-question row on the settings page.
 *
 * @param int|string $i Row index (or the literal {{i}} placeholder in the JS template)
 * @param array $q
 * @param array $types
 * @return void
 */
function evreq_render_question_row($i, $q, $types) {
    $name = EVREQ_OPTION . '[custom_questions][' . $i . ']';
    $options_text = !empty($q['options']) ? implode("\n", $q['options']) : '';
    $needs_options = in_array($q['type'], array('select', 'checkbox'), true);
    ?>
    <div class="evreq-q-row">
        <input type="text" class="evreq-q-label" name="<?php echo esc_attr($name); ?>[label]"
               value="<?php echo esc_attr($q['label']); ?>" placeholder="Question label">
        <select class="evreq-q-type" name="<?php echo esc_attr($name); ?>[type]">
            <?php foreach ($types as $value => $label) : ?>
                <option value="<?php echo esc_attr($value); ?>" <?php selected($q['type'], $value); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <textarea class="evreq-q-options" name="<?php echo esc_attr($name); ?>[options]"
                  placeholder="One option per line" rows="2"
                  style="<?php echo $needs_options ? '' : 'display:none;'; ?>"><?php echo esc_textarea($options_text); ?></textarea>
        <label class="evreq-q-required">
            <input type="checkbox" name="<?php echo esc_attr($name); ?>[required]" value="1" <?php checked($q['required'], 1); ?>>
            Required
        </label>
        <button type="button" class="button-link evreq-q-remove" aria-label="Remove question">&times;</button>
    </div>
    <?php
}

// ============================================================
// Remote config (region + type dropdowns)
// ============================================================

/**
 * The region and event-type lists from the forum, cached for an hour so the form does not
 * call the forum on every page view.
 *
 * @param bool $force Skip the cache (used on the settings page to show live status)
 * @return array|WP_Error {regions, event_types, title_max}
 */
function evreq_remote_config($force = false) {
    $settings = evreq_settings();
    if (empty($settings['endpoint_url']) || empty($settings['token'])) {
        return new WP_Error('evreq_unconfigured', 'The endpoint URL and token are not set.');
    }

    if (!$force) {
        $cached = get_transient(EVREQ_CONFIG_TRANSIENT);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $response = wp_remote_get(add_query_arg('action', 'config', $settings['endpoint_url']), array(
        'timeout' => 10,
        'headers' => array('Authorization' => 'Bearer ' . $settings['token']),
    ));

    if (is_wp_error($response)) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($code !== 200 || !is_array($body) || empty($body['ok'])) {
        $message = is_array($body) && !empty($body['error']) ? $body['error'] : ('HTTP ' . $code);
        return new WP_Error('evreq_config_failed', $message);
    }

    $config = array(
        'regions'     => isset($body['regions']) ? array_values((array)$body['regions']) : array(),
        'event_types' => isset($body['event_types']) ? (array)$body['event_types'] : array(),
        'title_max'   => isset($body['title_max']) ? (int)$body['title_max'] : 64,
    );

    set_transient(EVREQ_CONFIG_TRANSIENT, $config, HOUR_IN_SECONDS);

    return $config;
}

// ============================================================
// The form
// ============================================================

add_shortcode('mybb_event_request', 'evreq_render_form');

/**
 * @return string
 */
function evreq_render_form() {
    wp_enqueue_style('evreq-form', plugins_url('assets/form.css', __FILE__), array(), '1.1.0');

    $settings = evreq_settings();
    $config = evreq_remote_config();
    $regions = (!is_wp_error($config) && !empty($config['regions'])) ? $config['regions'] : array();
    $title_max = (!is_wp_error($config) && !empty($config['title_max'])) ? (int)$config['title_max'] : 64;

    // Show the outcome of a submit that redirected back here.
    $notice = '';
    if (isset($_GET['evreq'])) {
        if ($_GET['evreq'] === 'ok') {
            $notice = '<div class="evreq-notice evreq-ok">' . esc_html($settings['success_text']) . '</div>';
        } elseif ($_GET['evreq'] === 'error') {
            $msg_key = EVREQ_RATELIMIT_PREFIX . 'msg_' . evreq_client_key();
            $errors = get_transient($msg_key);
            delete_transient($msg_key);
            $detail = is_array($errors) ? implode(' ', array_map('esc_html', $errors)) : 'Something went wrong sending your request. Please try again.';
            $notice = '<div class="evreq-notice evreq-error">' . $detail . '</div>';
        }
    }

    ob_start();
    echo $notice;

    if (empty($regions)) {
        echo '<p class="evreq-notice evreq-error">The event request form is not available right now. Please check back soon.</p>';
        return ob_get_clean();
    }

    $action = esc_url(admin_url('admin-post.php'));
    ?>
    <form class="evreq-form" method="post" action="<?php echo $action; ?>">
        <input type="hidden" name="action" value="evreq_submit">
        <?php wp_nonce_field('evreq_submit', 'evreq_nonce'); ?>

        <!-- Honeypot: a real person leaves this empty. Hidden from view in form.css. -->
        <div class="evreq-hp" aria-hidden="true">
            <label>Leave this field blank<input type="text" name="evreq_website" tabindex="-1" autocomplete="off"></label>
        </div>

        <p>
            <label for="evreq_title">Event name <span class="evreq-req">*</span></label>
            <input type="text" id="evreq_title" name="title" maxlength="<?php echo esc_attr($title_max); ?>" required>
        </p>

        <div class="evreq-row">
            <p>
                <label for="evreq_region">Region <span class="evreq-req">*</span></label>
                <select id="evreq_region" name="region" required>
                    <option value="">Choose a region</option>
                    <?php foreach ($regions as $region) : ?>
                        <option value="<?php echo esc_attr($region); ?>"><?php echo esc_html($region); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p>
                <label for="evreq_address">Venue address <span class="evreq-req">*</span></label>
                <input type="text" id="evreq_address" name="address" maxlength="255" required>
            </p>
        </div>

        <div class="evreq-row">
            <p>
                <label for="evreq_date">Event date <span class="evreq-req">*</span></label>
                <input type="date" id="evreq_date" name="start_date" required>
            </p>
            <p>
                <label for="evreq_start_time">Start time <span class="evreq-req">*</span></label>
                <input type="time" id="evreq_start_time" name="start_time" required>
            </p>
            <p>
                <label for="evreq_end_time">End time <span class="evreq-req">*</span></label>
                <input type="time" id="evreq_end_time" name="end_time" required>
            </p>
        </div>

        <p>
            <label for="evreq_description">Tell us about your event <span class="evreq-req">*</span></label>
            <textarea id="evreq_description" name="description" rows="4" required></textarea>
        </p>

        <fieldset class="evreq-fieldset">
            <legend>Your details</legend>
            <div class="evreq-row">
                <p>
                    <label for="evreq_contact_name">Your name <span class="evreq-req">*</span></label>
                    <input type="text" id="evreq_contact_name" name="contact_name" required>
                </p>
                <p>
                    <label for="evreq_contact_email">Your email <span class="evreq-req">*</span></label>
                    <input type="email" id="evreq_contact_email" name="contact_email" required>
                </p>
            </div>
            <p>
                <label for="evreq_contact_phone">Your phone</label>
                <input type="tel" id="evreq_contact_phone" name="contact_phone">
            </p>
        </fieldset>

        <?php if (!empty($settings['custom_questions'])) : ?>
            <fieldset class="evreq-fieldset">
                <legend>Event details</legend>
                <?php foreach ($settings['custom_questions'] as $i => $q) {
                    evreq_render_question_field($i, $q);
                } ?>
            </fieldset>
        <?php endif; ?>

        <p class="evreq-submit">
            <button type="submit">Send event request</button>
        </p>
    </form>
    <?php
    return ob_get_clean();
}

/**
 * Render one custom question as a form field.
 *
 * @param int $i
 * @param array $q
 * @return void
 */
function evreq_render_question_field($i, $q) {
    $id = 'evreq_custom_' . $i;
    $req = !empty($q['required']);
    $req_mark = $req ? ' <span class="evreq-req">*</span>' : '';
    $base = 'custom[' . $i . ']';

    echo '<div class="evreq-custom-q">';

    if ($q['type'] === 'checkbox') {
        echo '<span class="evreq-q-legend">' . esc_html($q['label']) . $req_mark . '</span>';
        foreach ($q['options'] as $opt) {
            printf(
                '<label class="evreq-check"><input type="checkbox" name="%s[]" value="%s"> %s</label>',
                esc_attr($base),
                esc_attr($opt),
                esc_html($opt)
            );
        }
        echo '</div>';
        return;
    }

    echo '<label for="' . esc_attr($id) . '">' . esc_html($q['label']) . $req_mark . '</label>';

    switch ($q['type']) {
        case 'textarea':
            printf('<textarea id="%s" name="%s" rows="3"%s></textarea>', esc_attr($id), esc_attr($base), $req ? ' required' : '');
            break;
        case 'select':
            printf('<select id="%s" name="%s"%s>', esc_attr($id), esc_attr($base), $req ? ' required' : '');
            echo '<option value="">Choose one</option>';
            foreach ($q['options'] as $opt) {
                printf('<option value="%s">%s</option>', esc_attr($opt), esc_html($opt));
            }
            echo '</select>';
            break;
        default: // text
            printf('<input type="text" id="%s" name="%s"%s>', esc_attr($id), esc_attr($base), $req ? ' required' : '');
            break;
    }

    echo '</div>';
}

// ============================================================
// Submit handling (server-to-server; token never touches the browser)
// ============================================================

add_action('admin_post_nopriv_evreq_submit', 'evreq_handle_submit');
add_action('admin_post_evreq_submit', 'evreq_handle_submit');

/**
 * A stable-ish key for the client, for rate limiting and carrying the error message back.
 *
 * @return string
 */
function evreq_client_key() {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
    return md5($ip);
}

/**
 * Bounce back to the form the request came from, with an outcome flag.
 *
 * @param string $status 'ok' or 'error'
 * @param array $messages Error messages to show (stored in a short-lived transient)
 * @return void
 */
function evreq_redirect_back($status, $messages = array()) {
    $referer = wp_get_referer();
    if (!$referer) {
        $referer = home_url('/');
    }

    if ($status === 'error' && !empty($messages)) {
        set_transient(EVREQ_RATELIMIT_PREFIX . 'msg_' . evreq_client_key(), $messages, 120);
    }

    wp_safe_redirect(add_query_arg('evreq', $status, remove_query_arg('evreq', $referer)));
    exit;
}

function evreq_handle_submit() {
    // 1. Nonce
    if (!isset($_POST['evreq_nonce']) || !wp_verify_nonce($_POST['evreq_nonce'], 'evreq_submit')) {
        evreq_redirect_back('error', array('Your session expired. Please try again.'));
    }

    // 2. Honeypot - a bot fills it, a person does not.
    if (!empty($_POST['evreq_website'])) {
        // Pretend success so the bot learns nothing.
        evreq_redirect_back('ok');
    }

    // 3. Rate limit - one submit per client per 30 seconds.
    $rl_key = EVREQ_RATELIMIT_PREFIX . evreq_client_key();
    if (get_transient($rl_key)) {
        evreq_redirect_back('error', array('Please wait a moment before sending another request.'));
    }
    set_transient($rl_key, 1, 30);

    $settings = evreq_settings();
    if (empty($settings['endpoint_url']) || empty($settings['token'])) {
        evreq_redirect_back('error', array('The form is not configured yet.'));
    }

    // 4. Required core fields.
    $required = array('title', 'region', 'address', 'start_date', 'start_time', 'end_time', 'description', 'contact_name', 'contact_email');
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            evreq_redirect_back('error', array('Please fill in all the required fields.'));
        }
    }
    if (!is_email($_POST['contact_email'])) {
        evreq_redirect_back('error', array('Please enter a valid email address.'));
    }

    // 5. Required custom questions.
    $answers = isset($_POST['custom']) && is_array($_POST['custom']) ? wp_unslash($_POST['custom']) : array();
    foreach ($settings['custom_questions'] as $i => $q) {
        if (!empty($q['required'])) {
            $given = isset($answers[$i]) ? $answers[$i] : '';
            $empty = is_array($given) ? (count(array_filter($given, 'strlen')) === 0) : (trim((string)$given) === '');
            if ($empty) {
                evreq_redirect_back('error', array('Please answer: ' . $q['label']));
            }
        }
    }

    // 6. Build the native payload.
    $core_keys = array('title', 'region', 'address', 'description', 'start_date', 'start_time', 'end_time');
    $payload = array();
    foreach ($core_keys as $key) {
        $payload[$key] = isset($_POST[$key]) ? sanitize_textarea_field(wp_unslash($_POST[$key])) : '';
    }
    // The form asks for one event date; the event runs start to end on that day.
    $payload['end_date'] = $payload['start_date'];

    // 7. Compose the request-details block: the requester, then every custom answer, as
    //    plain "Label: value" lines. The forum appends this to the event description. Kept
    //    forum-agnostic (no BBCode) - the MyBB endpoint owns the formatting.
    $details = array();
    $details[] = 'Submitted by: ' . sanitize_text_field(wp_unslash($_POST['contact_name']));
    $details[] = 'Email: ' . sanitize_email(wp_unslash($_POST['contact_email']));
    if (!empty($_POST['contact_phone'])) {
        $details[] = 'Phone: ' . sanitize_text_field(wp_unslash($_POST['contact_phone']));
    }

    foreach ($settings['custom_questions'] as $i => $q) {
        $given = isset($answers[$i]) ? $answers[$i] : '';
        if (is_array($given)) {
            $values = array_map('sanitize_text_field', $given);
            $values = array_filter($values, 'strlen');
            $value = implode(', ', $values);
        } else {
            $value = sanitize_text_field($given);
        }
        if ($value !== '') {
            $details[] = $q['label'] . ': ' . $value;
        }
    }

    $payload['request_details'] = implode("\n", $details);

    // 8. Server-to-server POST with the bearer token.
    $response = wp_remote_post($settings['endpoint_url'], array(
        'timeout' => 15,
        'headers' => array(
            'Authorization' => 'Bearer ' . $settings['token'],
            'Content-Type'  => 'application/json; charset=utf-8',
            'Accept'        => 'application/json',
        ),
        'body' => wp_json_encode($payload),
    ));

    if (is_wp_error($response)) {
        evreq_redirect_back('error', array('Could not reach the garrison right now. Please try again shortly.'));
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($code === 201 && is_array($body) && !empty($body['ok'])) {
        evreq_redirect_back('ok');
    }

    // Surface the forum's own validation messages when it sent them.
    if (is_array($body) && !empty($body['errors'])) {
        evreq_redirect_back('error', array_map('strval', $body['errors']));
    }

    $message = is_array($body) && !empty($body['error']) ? $body['error'] : 'Your request could not be sent. Please try again.';
    evreq_redirect_back('error', array($message));
}
