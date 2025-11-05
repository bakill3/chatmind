<?php
/**
 * Lightweight feedback loop: update uploads.profile_json (and file) with approval-derived stats.
 * This avoids heavy re-parsing of chat logs and updates only the approval-related metrics.
 */
function update_profile_from_approvals(PDO $pdo, int $user_id, ?int $upload_id = null, ?string $contact_name = null): void {
    if ($user_id <= 0) return;
    $pdo->query("SET NAMES utf8mb4");

    // compute tone/length win-rates like build_profile used
    $tone_stats = ['short'=>['wins'=>0,'trials'=>0],'long'=>['wins'=>0,'trials'=>0]];
    try {
        $sql = "SELECT approved_reply, received_text, is_approved FROM approved_messages WHERE user_id = ?";
        $params = [$user_id];
        if ($upload_id) { $sql .= " AND upload_id = ?"; $params[] = $upload_id; }
        if ($contact_name) { $sql .= " AND contact_name = ?"; $params[] = $contact_name; }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $recv = trim($r['received_text'] ?? ''); $rep = trim($r['approved_reply'] ?? '');
            if ($recv === '' || $rep === '') continue;
            $len_rep = mb_strlen($rep);
            $t = ($len_rep < 80) ? 'short' : 'long';
            $tone_stats[$t]['trials']++;
            if ((int)$r['is_approved'] === 1) $tone_stats[$t]['wins']++;
        }
    } catch (\Throwable $e) {
        // ignore failures
    }

    $tone_win_rates = [];
    foreach ($tone_stats as $k=>$v) {
        $tone_win_rates[$k] = $v['trials'] ? round(100.0 * $v['wins'] / $v['trials'],1) : null;
        $tone_win_rates[$k.'_trials'] = $v['trials'];
    }

    // load current profile_json from DB
    if ($upload_id) {
        $stmt = $pdo->prepare("SELECT profile_json FROM uploads WHERE id = ? AND user_id = ?");
        $stmt->execute([$upload_id, $user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $profile = [];
        if ($row && !empty($row['profile_json'])) {
            $profile = json_decode($row['profile_json'], true) ?: [];
        }
        if (!isset($profile['enriched'])) $profile['enriched'] = [];
        $profile['enriched']['tone_win_rates'] = $tone_win_rates;
        $profile['enriched']['approved_messages_count'] = ($tone_stats['short']['trials'] + $tone_stats['long']['trials']);

        // persist to file
        if (!is_dir(PROFILE_JSON_DIR)) @mkdir(PROFILE_JSON_DIR, 0755, true);
        $jsonPath = rtrim(PROFILE_JSON_DIR, "\/\\") . DIRECTORY_SEPARATOR . $upload_id . '.json';
        @file_put_contents($jsonPath, json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        // persist to DB
        try {
            $up = $pdo->prepare("UPDATE uploads SET profile_json = ?, profile_updated_at = NOW() WHERE id = ?");
            $up->execute([json_encode($profile, JSON_UNESCAPED_UNICODE), $upload_id]);
        } catch (\Throwable $e) {
            // ignore DB write errors to keep approval flow fast
        }
    }
}
