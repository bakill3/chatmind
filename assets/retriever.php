<?php
/**
 * Simple local retriever helper using bag-of-words + TF-IDF-like weighting.
 * Keeps everything local and lightweight (no external libs).
 * Provides: retrieve_similar_snippets(array $messages, string $query, int $k=5, int $min_len=20)
 */

function normalize_text_for_retrieval(string $s): string {
    $s = mb_strtolower($s);
    $s = preg_replace('/[^\p{L}0-9\s]/u', ' ', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
}

function tokenize(string $s): array {
    if ($s === '') return [];
    return array_filter(array_map('trim', explode(' ', $s)), fn($t) => $t !== '');
}

function build_term_counts(array $docs): array {
    $termCounts = [];
    foreach ($docs as $i => $d) {
        $toks = array_unique(tokenize(normalize_text_for_retrieval($d)));
        foreach ($toks as $t) {
            if ($t === '') continue;
            $termCounts[$t] = ($termCounts[$t] ?? 0) + 1;
        }
    }
    return $termCounts; // document frequencies
}

function vectorize(string $text, array $vocab): array {
    $vec = array_fill_keys($vocab, 0.0);
    $toks = tokenize(normalize_text_for_retrieval($text));
    foreach ($toks as $t) {
        if (isset($vec[$t])) $vec[$t] += 1.0;
    }
    // normalize by length
    $len = array_sum($vec) ?: 1.0;
    foreach ($vec as $k => $v) $vec[$k] = $v / $len;
    return $vec;
}

function cosine_sim(array $a, array $b): float {
    $num = 0.0; $na = 0.0; $nb = 0.0;
    foreach ($a as $k => $va) {
        $vb = $b[$k] ?? 0.0;
        $num += $va * $vb;
        $na += $va * $va;
        $nb += $vb * $vb;
    }
    if ($na <= 0 || $nb <= 0) return 0.0;
    return $num / (sqrt($na) * sqrt($nb));
}

/**
 * Retrieve top-k similar message snippets from $messages.
 * $messages: array of ['sender'=>..., 'message'=>..., 'time' => ...?]
 */
function retrieve_similar_snippets(array $messages, string $query, int $k = 5, int $min_len = 20): array {
    $candidates = [];
    foreach ($messages as $m) {
        $txt = trim($m['message'] ?? '');
        if ($txt === '') continue;
        if (mb_strlen($txt) < $min_len) continue;
        $candidates[] = $txt;
    }
    if (!$candidates) return [];

    // build vocab from candidates + query (top unique tokens)
    $all = $candidates;
    $all[] = $query;
    $vocabCounts = build_term_counts($all);
    // keep vocab limited to most common ~200 terms to keep vectors small
    arsort($vocabCounts);
    $vocab = array_slice(array_keys($vocabCounts), 0, 200);
    if (!$vocab) return [];

    $qv = vectorize($query, $vocab);
    $scores = [];
    foreach ($candidates as $i => $txt) {
        $tv = vectorize($txt, $vocab);
        $sim = cosine_sim($qv, $tv);
        $scores[] = ['score' => $sim, 'text' => $txt];
    }
    usort($scores, fn($a,$b)=>($b['score'] <=> $a['score']));
    $out = array_slice($scores, 0, $k);
    // return text + score
    return array_map(fn($s)=>['text'=>$s['text'],'score'=>$s['score']], $out);
}

