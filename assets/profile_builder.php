<?php
require_once __DIR__ . '/../config.php';

// Minimal rebuild function used by generate.php to (re)create profile JSON file for an upload
function rebuild_profile(int $upload_id, PDO $pdo, int $user_id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM uploads WHERE id=? AND user_id=?");
    $stmt->execute([$upload_id, $user_id]);
    $up = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$up) return null;
    $filepath = UPLOADS_DIR . '/' . $up['filename'];
    if (!is_file($filepath)) return null;
    $txt = file_get_contents($filepath);

    // Use the parse logic similar to build_profile
    $lines = preg_split("/\R/u", $txt);
    $re = '/^\s*\[?(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4}),?\s+(\d{1,2}):(\d{2})(?::(\d{2}))?\]?[\s\-–]*([^:]+):\s*(.*)$/u';
    $out = [];
    $cur = null;
    foreach ($lines as $raw) {
        $line = to_utf8($raw);
        if ($line === '') continue;
        if (preg_match($re, $line, $m)) {
            if ($cur && ($cur['message'] ?? '') !== '') {
                $cur['message'] = preg_replace('/\s+/u',' ', trim($cur['message']));
                $out[] = $cur;
            }
            $day = (int)$m[1]; $mon = (int)$m[2]; $yr = (int)$m[3];
            if ($yr < 100) { $yr += ($yr > 30 ? 1900 : 2000); }
            $hour = (int)$m[4]; $min = (int)$m[5]; $sec = isset($m[6]) ? (int)$m[6] : 0;
            $sender = trim(to_utf8($m[7] ?? ''));
            $msg = trim(to_utf8($m[8] ?? ''));
            $time = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $yr, $mon, $day, $hour, $min, $sec);
            $cur = ['sender'=>$sender, 'message'=>$msg, 'time'=>$time];
        } else {
            if ($cur) $cur['message'] .= ' ' . to_utf8(trim($line));
        }
    }
    if ($cur && ($cur['message'] ?? '') !== '') { $cur['message'] = preg_replace('/\s+/u',' ', trim($cur['message'])); $out[] = $cur; }

    // write profile using compute_enriched_profile logic from build_profile.php
    // replicate compute_enriched_profile minimal functionality here to avoid coupling
    // For brevity, we will calculate basic stats: active_hours, avg lengths and keywords using simple counts
    $timestamps = [];
    foreach ($out as $m) {
        $t = $m['time'] ?? null; if ($t) { $ts = strtotime($t); if ($ts !== false) $timestamps[] = ['sender'=>$m['sender'],'time'=>$ts]; }
    }
    $hours = array_fill(0,24,0);
    foreach ($timestamps as $r) { $h = (int)gmdate('G', $r['time']); $hours[$h]++; }

    $lens = ['me'=>['count'=>0,'sum'=>0],'others'=>['count'=>0,'sum'=>0]];
    $stmt = $pdo->prepare("SELECT fullname FROM users WHERE id=?"); $stmt->execute([$user_id]); $reg_name = $stmt->fetchColumn() ?: '';
    foreach ($out as $m) {
        $s = $m['sender'] ?? ''; $msg = $m['message'] ?? ''; if ($msg === '') continue;
        $len = mb_strlen($msg);
        if (mb_strtolower($s) === mb_strtolower($reg_name)) { $lens['me']['count']++; $lens['me']['sum'] += $len; } else { $lens['others']['count']++; $lens['others']['sum'] += $len; }
    }
    $avg_me = $lens['me']['count'] ? round($lens['me']['sum'] / $lens['me']['count'],1) : 0;
    $avg_others = $lens['others']['count'] ? round($lens['others']['sum'] / $lens['others']['count'],1) : 0;

    $wordCounts = [];
    foreach ($out as $m) {
        foreach (explode(' ', preg_replace('/[^\p{L}0-9\s]/u',' ', mb_strtolower($m['message']))) as $w) { $w=trim($w); if (mb_strlen($w)>2) $wordCounts[$w]=($wordCounts[$w]??0)+1; }
    }
    arsort($wordCounts);
    $keywords = array_slice(array_keys($wordCounts),0,12);

    $profile = [
        'relationship'=>'unknown',
        'my_name_guess'=>$reg_name,
        'other_primary'=>'',
        'tone'=>['concise'],
        'style_notes'=>'Rebuilt profile',
        'stats'=>['total_messages'=>count($out)],
        'enriched'=>['active_hours'=>$hours,'avg_length_me'=>$avg_me,'avg_length_others'=>$avg_others,'avg_reply_latency_seconds'=>null,'keywords'=>$keywords]
    ];

    if (!is_dir(PROFILE_JSON_DIR)) @mkdir(PROFILE_JSON_DIR,0755,true);
    $jsonPath = rtrim(PROFILE_JSON_DIR, "\/\\") . DIRECTORY_SEPARATOR . $upload_id . '.json';
    @file_put_contents($jsonPath, json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    try { $up = $pdo->prepare("UPDATE uploads SET profile_json = ?, profile_updated_at = NOW() WHERE id = ?"); $up->execute([json_encode($profile, JSON_UNESCAPED_UNICODE), $upload_id]); } catch (\Throwable $e) {}
    return $profile;
}
