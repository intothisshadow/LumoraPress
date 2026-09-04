<?php

/**
 * Shared AJAX sub-action: persists a signed-in user's saved item order
 * (and, where applicable, collapsed-item state) for a sortable.js
 * AJAX-mode group — covers the Post/Page editor sidebar and the
 * Dashboard's widget order (no collapse concept there, so 'collapsed'
 * stays empty for that screen type).
 *
 * @package LumoraPress
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.6.0
 */
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var \LumoraPress\Models\User $currentUser */

use LumoraPress\Core\Security\Csrf;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['form'] ?? null) === 'save_editor_layout') {
    // Discard the output buffer layout-header.php already started
    // before a JSON response, or the buffered HTML would flush alongside it.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json');

    $screenType = (string) ($_POST['screen_type'] ?? '');

    if (!in_array($screenType, ['post', 'page', 'dashboard'], true)
        || !Csrf::verify('editor_layout_' . $screenType, is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)
    ) {
        http_response_code(403);
        echo json_encode(['error' => 'Your session expired. Reload the page and try again.']);
        exit;
    }

    $order = array_values(array_filter((array) ($_POST['order'] ?? []), 'is_string'));
    $collapsed = array_values(array_filter((array) ($_POST['collapsed'] ?? []), 'is_string'));

    $kernel->users->updateEditorLayoutPreferences($currentUser->id, $screenType, $order, $collapsed);

    echo json_encode(['success' => true, 'csrfToken' => Csrf::token('editor_layout_' . $screenType)]);
    exit;
}
